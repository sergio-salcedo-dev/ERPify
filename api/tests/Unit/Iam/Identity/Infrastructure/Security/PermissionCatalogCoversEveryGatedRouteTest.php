<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Security;

use Erpify\Backoffice\Bank\Infrastructure\Controller\BankRealtimeAuthorizeController;
use Erpify\Iam\Identity\Infrastructure\Security\Permission;
use Erpify\Iam\Identity\Infrastructure\Security\PermissionCatalog;
use Erpify\Shared\Http\Infrastructure\ApiRequestMatcher;
use Erpify\Tests\Support\ApiSourceFiles;
use Erpify\Tests\Support\ImperativePermissionChecks;
use Erpify\Tests\Unit\Iam\Identity\Infrastructure\Security\Fixtures\SubclassGatedFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionAttribute;
use ReflectionClass;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Yaml\Yaml;

/**
 * The gate that makes a central catalog safe to have. Enumerating permissions costs the design its best
 * property — a brand-new resource used to be governed on tier verbs alone, with zero configuration — and
 * without this test the price would be paid in silence: a permission gated on a route but missing from the
 * catalog still authorizes correctly server-side, it merely vanishes from `/me`, so the client hides a
 * control the user is entitled to and nothing anywhere fails. That is the worst kind of bug: invisible,
 * and indistinguishable from a deliberate denial.
 *
 * So the omission is made loud. Every `#[IsGranted]` in the tree is read back and matched against the
 * catalog; forgetting an entry fails the build with the permission and the file that needs it. The property
 * is not restored — it is traded for one that can be enforced.
 *
 * The attribute is not the only way a route is gated. A controller may also ask the authorization checker
 * mid-method — the shape a conditional grant needs, where the permission depends on the payload rather than on
 * the route — and reflection cannot see those at all. {@see ImperativePermissionChecks} reads them out of the
 * source instead, and the two sets are held to the same rule, so the sweep's coverage does not depend on which
 * of the two spellings an author reached for.
 *
 * A superset, never an equality: the catalog also carries permissions whose use case has not landed yet
 * (`users.invite` and friends), mirroring the seam the policy documents.
 *
 * @internal
 */
#[CoversClass(PermissionCatalog::class)]
final class PermissionCatalogCoversEveryGatedRouteTest extends TestCase
{
    private const string PLACEHOLDER_SAMPLE = '0190c1d2-e3f4-7a5b-8c6d-1e2f3a4b5c6d';

    /** Two API paths the engine cases are judged against, standing in for the route manifest. */
    private const array ENGINE_PROBES = ['/api/v1/health', '/api/v1/backoffice/users/' . self::PLACEHOLDER_SAMPLE];

    public function testEveryGatedPermissionIsInTheCatalog(): void
    {
        $catalog = new PermissionCatalog();
        $gated = $this->gatedPermissions();

        $this->assertNotEmpty($gated, 'No #[IsGranted] permissions were found — the sweep is not reaching src.');

        foreach ($gated as $permission => $declaredIn) {
            $this->assertTrue($catalog->contains($permission), \sprintf(
                'Permission "%s" gates %s but is absent from PermissionCatalog. Add it: `/me` cannot advertise '
                . 'a permission the catalog does not list, so the client would silently hide the control.',
                $permission,
                $declaredIn,
            ));
        }
    }

    public function testEveryImperativelyCheckedPermissionIsInTheCatalog(): void
    {
        $catalog = new PermissionCatalog();
        $checked = ImperativePermissionChecks::in($this->apiSources());

        $this->assertNotEmpty(
            $checked,
            'No imperative permission check was found. Either the sweep stopped reaching src — in which case '
            . 'this assertion is the only thing standing between a missing catalog entry and silence — or the '
            . 'last conditional gate was removed and this test has nothing left to guard.',
        );

        foreach ($checked as $permission => $declaredIn) {
            $this->assertTrue($catalog->contains($permission), \sprintf(
                'Permission "%s" is checked imperatively in %s but is absent from PermissionCatalog. The '
                . 'attribute sweep cannot see this call, so nothing else would have told you: add it, or `/me` '
                . 'will never advertise it and the client will hide a control the user holds.',
                $permission,
                $declaredIn,
            ));
        }
    }

    /**
     * Sanity anchor for the sweep itself: most controllers are gated on the class, the realtime authorize
     * endpoints on the method. Reading only class attributes would leave the assertion above vacuously true
     * over a smaller set, so the method path is pinned against a controller that has nothing at class level —
     * if the sweep still finds its permission, it can only have come from the method.
     */
    public function testTheSweepReadsAttributesDeclaredOnMethodsNotOnlyOnClasses(): void
    {
        $methodGated = new ReflectionClass(BankRealtimeAuthorizeController::class);

        $this->assertEmpty(
            $methodGated->getAttributes(IsGranted::class),
            'Precondition drifted: this controller is now gated at class level, '
            . 'so it no longer exercises the method path.',
        );
        $this->assertNotEmpty(
            $this->isGrantedAttributesOf($methodGated),
            'The sweep must read #[IsGranted] declared on methods, not just on classes.',
        );
    }

    /**
     * Every permission string reached by an `#[IsGranted]` anywhere under `api/src`, mapped to a class that
     * declares it (for the failure message). Attributes are read off both the class and its methods, since
     * the codebase gates both ways.
     *
     * @return array<string, string> permission => a declaring class short name
     */
    private function gatedPermissions(): array
    {
        $gated = [];

        foreach ($this->classesUnderApiSrc() as $reflectionClass) {
            $unclassifiable = [];

            foreach ($this->permissionsDeclaredOn($reflectionClass, $unclassifiable) as $permission) {
                $gated[$permission] = $reflectionClass->getShortName();
            }

            $this->assertSame([], $unclassifiable, \sprintf(
                '%s gates a route with an #[IsGranted] whose argument is not a plain string (an Expression or a '
                . 'Closure). The catalog sweep cannot tell which permission it governs, so `/me` could omit it '
                . 'without anything failing: gate on a "resource.action" string, or teach this sweep to classify it.',
                $reflectionClass->getName(),
            ));
        }

        return $gated;
    }

    /**
     * Permission strings gated on a class and its methods, `IsGranted` subclasses included. The attribute
     * lookup is by instance-of, not by exact name: `IsGranted` is not final, so a project-local subclass
     * would otherwise gate a route while the sweep saw nothing.
     *
     * @template T of object
     *
     * @param ReflectionClass<T> $reflectionClass
     * @param list<string>       $unclassifiable  receives a description of each non-string attribute argument
     *
     * @return list<string>
     */
    private function permissionsDeclaredOn(ReflectionClass $reflectionClass, array &$unclassifiable): array
    {
        $permissions = [];

        foreach ($this->isGrantedAttributesOf($reflectionClass) as $attribute) {
            $declared = $attribute->newInstance()->attribute;

            if (!\is_string($declared)) {
                $unclassifiable[] = \get_debug_type($declared);

                continue;
            }

            // Role and authenticated tokens (`ROLE_*`, `IS_AUTHENTICATED_*`, `PUBLIC_ACCESS`) carry no
            // `resource.action` separator and are not permissions this catalog governs.
            if (Permission::isWellFormed($declared)) {
                $permissions[] = $declared;
            }
        }

        return $permissions;
    }

    /**
     * `IsGranted` is repeatable, so every attribute is read, not just the first.
     *
     * @template T of object
     *
     * @param ReflectionClass<T> $reflectionClass
     *
     * @return list<ReflectionAttribute<IsGranted>>
     */
    private function isGrantedAttributesOf(ReflectionClass $reflectionClass): array
    {
        $attributes = $reflectionClass->getAttributes(IsGranted::class, ReflectionAttribute::IS_INSTANCEOF);

        foreach ($reflectionClass->getMethods() as $reflectionMethod) {
            $attributes = [
                ...$attributes,
                ...$reflectionMethod->getAttributes(IsGranted::class, ReflectionAttribute::IS_INSTANCEOF),
            ];
        }

        return $attributes;
    }

    /**
     * `security.yaml` `access_control` is a second gating surface over `/api`. Today every rule there is
     * `PUBLIC_ACCESS` or the `IS_AUTHENTICATED_FULLY` catch-all; a rule that gated a path on a permission
     * (or on an `allow_if` expression) would bypass the attribute sweep entirely.
     *
     * Every `access_control` list in the file is read — the top-level one and the one under each `when@<env>`
     * key — because an environment block is as live in its environment as the top level is everywhere. A rule is
     * in scope when its path regex REACHES an API route, tested the way Symfony's `PathRequestMatcher` tests it
     * (`{<path>}` against the path) over every route of `api/.route-manifest.json`: a literal `/api` in the
     * pattern is neither necessary (`/v1/backoffice/` reaches the same routes unanchored) nor sufficient. A rule
     * with no path reaches everything; a pattern that does not compile, or a `request_matcher` this sweep cannot
     * evaluate, fails closed. The manifest is the prod router's inventory, so a rule reaching only a route prod
     * does not serve — a dev or test route (`^/api/test/` today), or one that does not exist yet — stays out of
     * scope until such a route reaches prod, and `make php.lint.route-manifest` refuses a route the manifest does
     * not list.
     */
    public function testAccessControlRulesDoNotGateApiPathsOutsideTheCatalog(): void
    {
        $config = Yaml::parseFile(self::apiRoot() . '/config/packages/security.yaml', Yaml::PARSE_CONSTANT);
        $this->assertIsArray($config);
        $rulesByBlock = $this->accessControlRulesIn($config);

        $this->assertArrayHasKey(
            'security',
            $rulesByBlock,
            'security.yaml no longer declares a top-level access_control: update this sweep.',
        );
        $this->assertNotEmpty($rulesByBlock['security']);

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
            $this->assertSame(1, \preg_match('{' . ApiRequestMatcher::PATH_PATTERN . '}', $probe), \sprintf(
                'Probe "%s" is not under the API boundary: the manifest is no longer the API route inventory.',
                $probe,
            ));
        }

        $this->assertTrue($this->reaches(ApiRequestMatcher::PATH_PATTERN, $probes));
    }

    public function testTheSweepSeesIsGrantedSubclasses(): void
    {
        $unclassifiable = [];

        $this->assertSame(
            ['widgets.read'],
            $this->permissionsDeclaredOn(new ReflectionClass(SubclassGatedFixture::class), $unclassifiable),
            'An IsGranted subclass must be swept: an exact-name lookup would report nothing here.',
        );
        $this->assertSame([], $unclassifiable);
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
        $rulesByBlock = $this->accessControlRulesIn([
            'security' => ['access_control' => [['path' => '^/api', 'roles' => 'IS_AUTHENTICATED_FULLY']]],
            'when@prod' => ['security' => ['access_control' => [['path' => '^/api/x', 'roles' => 'widgets.read']]]],
            'when@dev' => ['security' => ['access_control' => [['path' => '^/api/y', 'roles' => 'PUBLIC_ACCESS']]]],
            'when@test' => ['security' => ['password_hashers' => []]],
        ]);

        $this->assertSame(['security', 'when@prod:security', 'when@dev:security'], \array_keys($rulesByBlock));
        $this->assertSame([['path' => '^/api/x', 'roles' => 'widgets.read']], $rulesByBlock['when@prod:security']);
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
        $manifest = \json_decode((string) \file_get_contents(self::apiRoot() . '/.route-manifest.json'), true);
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

        foreach ($probes as $probe) {
            if (1 === \preg_match('{' . $pattern . '}', $probe)) {
                return true;
            }
        }

        return false;
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

    /**
     * The conditional grant the imperative sweep exists for, pinned by name. Without it the assertion above
     * would survive a regex that matched nothing in particular — `assertNotEmpty` only proves the sweep found
     * *something*, not that it found the call shape actually in the tree, which passes its permission as a
     * class constant rather than as a literal.
     */
    public function testTheImperativeSweepResolvesAPermissionPassedAsAClassConstant(): void
    {
        $this->assertContains(
            'users.grantAdmin',
            \array_keys(ImperativePermissionChecks::in($this->apiSources())),
            'The delegation gate is checked as `self::GRANT_ADMIN_PERMISSION`; a sweep that only understood '
            . 'quoted literals would report nothing here and stay green over an empty set.',
        );
    }

    /**
     * @return array<string, string> path relative to `api/src` => file contents
     */
    private function apiSources(): array
    {
        $srcRoot = ApiSourceFiles::root();
        $sources = [];

        foreach (ApiSourceFiles::phpFiles($srcRoot) as $file) {
            $sources[\substr($file->getPathname(), \strlen($srcRoot) + 1)] = (string) \file_get_contents(
                $file->getPathname(),
            );
        }

        return $sources;
    }

    private static function apiRoot(): string
    {
        return \dirname(__DIR__, 6);
    }

    /**
     * Autoloadable classes under `api/src`, derived from the shared {@see ApiSourceFiles} walk plus the PSR-4
     * `Erpify\` → `src/` mapping — the same layering the error-contract marker gate uses.
     *
     * @return iterable<ReflectionClass<object>>
     */
    private function classesUnderApiSrc(): iterable
    {
        $srcRoot = ApiSourceFiles::root();

        foreach (ApiSourceFiles::phpFiles($srcRoot) as $file) {
            $relative = \substr($file->getPathname(), \strlen($srcRoot) + 1, -\strlen('.php'));
            $fqcn = 'Erpify\\' . \str_replace('/', '\\', $relative);

            if (!\class_exists($fqcn)) {
                continue;
            }

            yield new ReflectionClass($fqcn);
        }
    }
}
