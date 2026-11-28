<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Security;

use Erpify\Iam\Identity\Infrastructure\Security\Permission;
use Erpify\Iam\Identity\Infrastructure\Security\PermissionCatalog;
use Erpify\Shared\Http\Infrastructure\ApiRequestMatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * `security.yaml` `access_control` is the gating surface {@see PermissionCatalogCoversEveryGatedRouteTest} cannot
 * reflect. Today every rule there is `PUBLIC_ACCESS` or the `IS_AUTHENTICATED_FULLY` catch-all; a rule that gated
 * a path on a permission (or on an `allow_if` expression) would bypass the attribute sweep entirely, and `/me`
 * would never advertise the permission it needs.
 *
 * Every `access_control` list in the file is read — the top-level one and the one under each `when@<env>` key —
 * because an environment block is as live in its environment as the top level is everywhere. A rule is in scope
 * when its path regex REACHES an API route, tested the way Symfony's `PathRequestMatcher` tests it (`{<path>}`
 * against the path) over every route of `api/.route-manifest.json`: a literal `/api` in the pattern is neither
 * necessary (`/v1/backoffice/` reaches the same routes unanchored) nor sufficient. A rule with no path reaches
 * everything; a pattern that does not compile, a non-string path or a `request_matcher` this sweep cannot
 * evaluate fails closed. The manifest is the prod router's inventory, so a rule reaching only a route prod does
 * not serve — a dev or test route (`^/api/test/` today), or one that does not exist yet — stays out of scope
 * until such a route reaches prod, and `make php.lint.route-manifest` refuses a route the manifest does not list.
 *
 * @internal
 */
#[CoversClass(PermissionCatalog::class)]
final class PermissionCatalogCoversAccessControlTest extends TestCase
{
    private const string PLACEHOLDER_SAMPLE = '0190c1d2-e3f4-7a5b-8c6d-1e2f3a4b5c6d';

    /** Two API paths the engine cases are judged against, standing in for the route manifest. */
    private const array ENGINE_PROBES = ['/api/v1/health', '/api/v1/backoffice/users/' . self::PLACEHOLDER_SAMPLE];

    public function testAccessControlRulesDoNotGateApiPathsOutsideTheCatalog(): void
    {
        $config = Yaml::parseFile($this->apiRoot() . '/config/packages/security.yaml', Yaml::PARSE_CONSTANT);
        $this->assertIsArray($config);
        $rulesByBlock = $this->accessControlRulesIn($config);

        $this->assertNotEmpty(
            $rulesByBlock['security'] ?? [],
            'security.yaml no longer declares a top-level access_control: update this sweep.',
        );

        $probes = $this->apiRouteProbes();
        $catalog = new PermissionCatalog();
        $problems = [];

        foreach ($rulesByBlock as $block => $rules) {
            foreach ($this->accessControlProblems($rules, $catalog, $probes) as $problem) {
                $problems[] = $block . ': ' . $problem;
            }
        }

        $this->assertSame([], $problems);
    }

    /**
     * A reachability verdict means something only over the API surface itself: every probe sits under the
     * session catch-all, a known route is among them, and the catch-all — the one rule that exists precisely to
     * reach them — is in scope.
     */
    public function testTheAccessControlProbesAreTheApiRoutes(): void
    {
        $probes = $this->apiRouteProbes();

        $this->assertContains('/api/v1/health', $probes, 'The route manifest no longer reaches the sweep.');

        foreach ($probes as $probe) {
            $this->assertMatchesRegularExpression('{' . ApiRequestMatcher::PATH_PATTERN . '}', $probe, \sprintf(
                'Probe "%s" is not under the API boundary: the manifest is no longer the API route inventory.',
                $probe,
            ));
        }

        $this->assertTrue($this->reaches(ApiRequestMatcher::PATH_PATTERN, $probes));
    }

    public function testTheAccessControlEngineRejectsUnclassifiedRules(): void
    {
        $catalog = new PermissionCatalog();
        $probes = self::ENGINE_PROBES;

        $this->assertSame([], $this->accessControlProblems([
            ['path' => '^/api/v1/health$', 'roles' => 'PUBLIC_ACCESS'],
            ['path' => '^/api', 'roles' => 'IS_AUTHENTICATED_FULLY'],
        ], $catalog, $probes));
        $this->assertCount(1, $this->accessControlProblems([
            ['path' => '^/api/v1/health$', 'roles' => 'widgets.read'],
        ], $catalog, $probes), 'A permission absent from the catalog must be reported.');
        $this->assertCount(1, $this->accessControlProblems([
            ['path' => '^/api/v1/health$', 'roles' => 'IS_AUTHENTICATED_FULLY', 'allow_if' => 'true'],
        ], $catalog, $probes), 'An allow_if expression must fail closed.');
        $this->assertCount(1, $this->accessControlProblems([
            ['path' => '^/api/v1/health$', 'roles' => ['ROLE_ADMIN']],
        ], $catalog, $probes), 'A role gate on an API path is not a catalog permission and must be classified.');
        $this->assertSame([], $this->accessControlProblems([
            ['path' => '^/admin', 'roles' => 'ROLE_ADMIN'],
        ], $catalog, $probes), 'A rule reaching no API route is out of scope.');
    }

    /**
     * Each shape below reaches an API route without carrying a literal `/api`, or cannot be evaluated at all;
     * every one must be reported, never skipped.
     */
    public function testTheAccessControlEngineScopesByReachNotByLiteral(): void
    {
        $catalog = new PermissionCatalog();

        foreach ([
            'an unanchored suffix' => ['path' => '/v1/backoffice/users', 'roles' => 'ROLE_ADMIN'],
            'a character class' => ['path' => '^/[a-z]+/v1/health$', 'roles' => 'ROLE_ADMIN'],
            'an alternation' => ['path' => '^/(?:x|ap)i/', 'roles' => 'ROLE_ADMIN'],
            'no path at all' => ['roles' => 'ROLE_ADMIN'],
            'an empty path' => ['path' => '', 'roles' => 'ROLE_ADMIN'],
            'a non-string path' => ['path' => ['^/api'], 'roles' => 'PUBLIC_ACCESS'],
            'a pattern that does not compile' => ['path' => '^/api(', 'roles' => 'PUBLIC_ACCESS'],
            'a request matcher service' => ['request_matcher' => 'app.matcher', 'roles' => 'PUBLIC_ACCESS'],
        ] as $shape => $rule) {
            $this->assertNotSame([], $this->accessControlProblems([$rule], $catalog, self::ENGINE_PROBES), \sprintf(
                'An access_control rule with %s must be reported.',
                $shape,
            ));
        }
    }

    public function testTheAccessControlCollectorReadsEveryEnvironmentBlock(): void
    {
        $this->assertSame(
            [
                'security' => [['path' => '^/api', 'roles' => 'IS_AUTHENTICATED_FULLY']],
                'when@prod:security' => [['path' => '^/api/x', 'roles' => 'widgets.read']],
                'when@dev:security' => [['path' => '^/api/y', 'roles' => 'PUBLIC_ACCESS']],
            ],
            $this->accessControlRulesIn([
                'security' => ['access_control' => [['path' => '^/api', 'roles' => 'IS_AUTHENTICATED_FULLY']]],
                'when@prod' => ['security' => ['access_control' => [['path' => '^/api/x', 'roles' => 'widgets.read']]]],
                'when@dev' => ['security' => ['access_control' => [['path' => '^/api/y', 'roles' => 'PUBLIC_ACCESS']]]],
                'when@test' => ['security' => ['password_hashers' => []]],
            ]),
        );
    }

    /**
     * Every `access_control` list of a parsed `security.yaml`, keyed by where it is declared.
     *
     * @param array<mixed> $config
     *
     * @return array<string, list<array<mixed>>>
     */
    private function accessControlRulesIn(array $config): array
    {
        $blocks = ['security' => $config['security'] ?? null];

        foreach ($config as $key => $value) {
            if (\is_string($key) && \str_starts_with($key, 'when@') && \is_array($value)) {
                $blocks[$key . ':security'] = $value['security'] ?? null;
            }
        }

        $rulesByBlock = [];

        foreach ($blocks as $block => $security) {
            $rules = \is_array($security) ? ($security['access_control'] ?? null) : null;

            if (\is_array($rules)) {
                $rulesByBlock[$block] = \array_values(\array_filter($rules, is_array(...)));
            }
        }

        return $rulesByBlock;
    }

    /**
     * Every route path of the committed prod router inventory, each placeholder filled with a UUID — the shape
     * every route id here takes — so a pattern constraining that segment is tested against a realistic value.
     *
     * @return list<string>
     */
    private function apiRouteProbes(): array
    {
        $manifest = \json_decode((string) \file_get_contents($this->apiRoot() . '/.route-manifest.json'), true);
        $this->assertIsArray($manifest, 'api/.route-manifest.json is not readable JSON.');

        $probes = [];

        foreach ($manifest as $route) {
            $path = \is_array($route) ? ($route['path'] ?? null) : null;
            $this->assertIsString($path, 'A route-manifest entry carries no path.');
            $probes[] = (string) \preg_replace('/\{[^}]+\}/', self::PLACEHOLDER_SAMPLE, $path);
        }

        return \array_values(\array_unique($probes));
    }

    /**
     * @param list<array<mixed>> $rules
     * @param list<string>       $probes
     *
     * @return list<string>
     */
    private function accessControlProblems(array $rules, PermissionCatalog $catalog, array $probes): array
    {
        $problems = [];

        foreach ($rules as $rule) {
            $problem = $this->unevaluableRuleProblem($rule);

            if (null !== $problem) {
                $problems[] = $problem;

                continue;
            }

            $rawPath = $rule['path'] ?? '';
            $path = \is_string($rawPath) ? $rawPath : '';

            if (!$this->reaches($path, $probes)) {
                continue;
            }

            if (isset($rule['allow_if'])) {
                $problems[] = \sprintf('access_control "%s" uses allow_if, which this sweep cannot classify.', $path);
            }

            foreach ((array) ($rule['roles'] ?? []) as $role) {
                $problem = $this->roleProblem($path, $role, $catalog);

                if (null !== $problem) {
                    $problems[] = $problem;
                }
            }
        }

        return $problems;
    }

    /**
     * A rule whose reach this sweep cannot compute, which fails closed rather than being read as out of scope.
     *
     * @param array<mixed> $rule
     */
    private function unevaluableRuleProblem(array $rule): ?string
    {
        if (isset($rule['request_matcher'])) {
            return 'an access_control rule uses request_matcher, which this sweep cannot evaluate.';
        }

        $rawPath = $rule['path'] ?? null;

        if (null === $rawPath) {
            return null;
        }

        if (!\is_string($rawPath)) {
            return 'an access_control rule declares a non-string path.';
        }

        if ('' !== $rawPath && !$this->compiles('{' . $rawPath . '}')) {
            return \sprintf('access_control "%s" is not a pattern this sweep can compile.', $rawPath);
        }

        return null;
    }

    /**
     * `preg_match` answers a pattern that does not compile with a warning and `false`; the warning is swallowed
     * here so the verdict, not PHPUnit's warning handler, decides the case.
     */
    private function compiles(string $delimitedPattern): bool
    {
        \set_error_handler(static fn (): bool => true);

        try {
            return false !== \preg_match($delimitedPattern, '');
        } finally {
            \restore_error_handler();
        }
    }

    /**
     * Whether an `access_control` path pattern matches at least one probe, delimited as `PathRequestMatcher`
     * delimits it. An empty pattern matches every path.
     *
     * @param list<string> $probes
     */
    private function reaches(string $pattern, array $probes): bool
    {
        if ('' === $pattern) {
            return true;
        }

        return \array_any($probes, static fn (string $probe): bool => 1 === \preg_match('{' . $pattern . '}', $probe));
    }

    private function roleProblem(string $path, mixed $role, PermissionCatalog $catalog): ?string
    {
        if (!\is_string($role)) {
            return \sprintf('access_control "%s" declares a non-string role.', $path);
        }

        if ('PUBLIC_ACCESS' === $role || \str_starts_with($role, 'IS_AUTHENTICATED_')) {
            return null;
        }

        if (Permission::isWellFormed($role) && $catalog->contains($role)) {
            return null;
        }

        return \sprintf(
            'access_control "%s" gates on "%s", which is neither a public/authenticated token nor a catalog '
            . 'permission: add it to PermissionCatalog or classify it here.',
            $path,
            $role,
        );
    }

    private function apiRoot(): string
    {
        return \dirname(__DIR__, 6);
    }
}
