<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Documents\Document\Domain\UploadLimits;
use Erpify\Documents\Document\Infrastructure\Http\UploadRoutes;
use Erpify\Shared\ErrorContract\Application\RequestUriRedaction;
use Erpify\Tests\Support\RepositoryRoot;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The evidence PUT's public path is written by the router and recognised by four patterns no router reads: the
 * API's whole-query redaction, its PWA mirror, and the two Caddyfile matchers that lift that route's body bound
 * from 1MB to the original's cap. Each pattern drifting from the route is silent — a redaction or a bound that
 * stops matching — so the path is taken from the router's committed inventory (`api/.route-manifest.json`,
 * regenerated from the prod router and gated against it) and every pattern must accept it and refuse a near miss.
 * Both Caddyfile matchers also lift the bound only for a request carrying the application's session cookie.
 *
 * The image write routes (logo, avatar) have their own matcher in the same file, bounded at
 * `erpify.images.max_input_bytes` and cookie-gated the same way. The matcher is proved on the two public shapes
 * written below, which hold independently of any router, and on any manifest route that looks like one: a consumer's
 * route either matches or reds this gate. The other direction — a route that carries `RawBodyRoute::ATTRIBUTE` or
 * reaches `ImageAttachment` while the matcher misses it — is `ImageWriteRouteAgreementGateTest`'s. What a text gate
 * cannot show — a body over 1MB reaching PHP whole — is a measurement through the running Caddy, and belongs beside
 * the route it measures (`BankLogoThroughCaddyTest`).
 *
 * @internal
 */
#[CoversNothing]
final class UploadRoutePathAgreementGateTest extends TestCase
{
    private const string ID = '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c';

    /** PHP's compiled-in `session.name`, used when no ini file the image installs and no framework config sets one. */
    private const string PHP_DEFAULT_SESSION_NAME = 'PHPSESSID';

    /**
     * Every site accepts the router's path and refuses a path that is a different route.
     */
    #[Test]
    public function everySiteRecognisesTheRoutersPathAndNoOtherRoute(): void
    {
        $path = $this->routerPath();
        $otherRoutes = [$path . '/more', $path . 's', \str_replace('/content', '/contents', $path)];

        foreach ([...$this->caddyPatterns('documentUpload'), ...$this->redactionPatterns()] as $site => $pattern) {
            $this->assertMatchesRegularExpression($pattern, $path, \sprintf('%s does not match %s', $site, $path));

            foreach ($otherRoutes as $other) {
                $this->assertDoesNotMatchRegularExpression($pattern, $other, \sprintf('%s matches %s', $site, $other));
            }
        }

        $this->assertTrue(RequestUriRedaction::withholdsWholeQuery($path), 'the API whole-query rule');

        foreach ($otherRoutes as $other) {
            $this->assertFalse(RequestUriRedaction::withholdsWholeQuery($other), 'the API rule: ' . $other);
        }
    }

    /**
     * The two families must disagree on purpose. The Caddy matchers lift the body bound, so they accept only the
     * canonical spelling — the front controller, a prefix or a trailing slash keeps the 1MB bound. The redaction rules
     * withhold a query, where over-matching is the safe direction, so they accept every spelling of the route.
     */
    #[Test]
    public function theBoundAcceptsOnlyTheCanonicalSpellingAndTheRedactionEveryOne(): void
    {
        $path = $this->routerPath();
        $spellings = ['/index.php' . $path, 'https://erpify.test' . $path, 'https://erpify.test/index.php' . $path];
        $notCanonical = [
            '/index.php' . $path,
            '/prefix' . $path,
            $path . '/',
            \strtoupper(\substr($path, 0, 4)) . \substr($path, 4),
        ];

        foreach ($this->caddyPatterns('documentUpload') as $site => $pattern) {
            foreach ($notCanonical as $spelling) {
                $this->assertDoesNotMatchRegularExpression(
                    $pattern,
                    $spelling,
                    \sprintf('%s lifts the bound for %s', $site, $spelling),
                );
            }
        }

        foreach ($this->redactionPatterns() as $site => $pattern) {
            foreach ($spellings as $spelling) {
                $this->assertMatchesRegularExpression($pattern, $spelling, \sprintf('%s misses %s', $site, $spelling));
            }
        }

        foreach ($spellings as $spelling) {
            $this->assertTrue(RequestUriRedaction::withholdsWholeQuery($spelling), 'the API rule misses ' . $spelling);
        }
    }

    /**
     * Each bound is lifted only for a request carrying the session cookie: at the top level of the matcher that lifts
     * it, and inside the `not { … }` group of the exclusion that keeps every other request at 1MB — moved outside
     * that group, it would bound every cookie-less request nowhere. Pinned against the cookie name the application
     * issues, so renaming the session cannot leave Caddy waiting for a cookie nobody sends.
     */
    #[Test]
    public function aBoundIsLiftedOnlyForARequestCarryingTheSessionCookie(): void
    {
        $cookie = $this->sessionCookieName();
        $conditions = [
            'documentUpload' => $this->matcherBlock('documentUpload'),
            'imageWrite' => $this->matcherBlock('imageWrite'),
        ];

        foreach ($this->exclusionGroups() as $index => $group) {
            $conditions['boundedBody exclusion #' . $index] = $group;
        }

        $this->assertCount(4, $conditions, 'The Caddyfile no longer holds the two matchers and their two exclusions.');

        foreach ($conditions as $matcher => $block) {
            $this->assertSame(1, \preg_match('~^\t+header_regexp Cookie (\S+)$~m', $block, $condition), \sprintf(
                '@%s no longer requires the session cookie where it must.',
                $matcher,
            ));
            $this->assertMatchesRegularExpression('~^\t+method PUT$~m', $block, $matcher . ' no longer names PUT');
            $pattern = '~' . $condition[1] . '~';

            $this->assertMatchesRegularExpression($pattern, $cookie . '=abc', $matcher);
            $this->assertMatchesRegularExpression($pattern, 'theme=dark; ' . $cookie . '=abc', $matcher);
            $this->assertDoesNotMatchRegularExpression($pattern, 'X' . $cookie . '=abc', $matcher);
            $this->assertDoesNotMatchRegularExpression($pattern, 'theme=dark', $matcher);
            $this->assertDoesNotMatchRegularExpression($pattern, $cookie . '=', $matcher);
        }
    }

    /**
     * A matcher that lifts a bound and the exclusion that keeps it off the 1MB rule are one condition written twice:
     * if they differ, a request is bounded twice or by nothing.
     */
    #[Test]
    public function eachLiftingMatcherAndItsExclusionSpellTheSamePath(): void
    {
        foreach (['documentUpload', 'imageWrite'] as $matcher) {
            $patterns = \array_values($this->caddyPatterns($matcher));
            $this->assertCount(2, $patterns);
            $this->assertSame($patterns[0], $patterns[1], $matcher);
        }
    }

    /**
     * The image bound is the byte cap the processor enforces, so an image the application accepts is never cut by the
     * edge and one it refuses is refused by the application with a Problem Details body.
     */
    #[Test]
    public function theImageBoundIsTheProcessorsByteCap(): void
    {
        $this->assertSame(
            1,
            \preg_match('~request_body @imageWrite \{\s*max_size (\d+)\s*\}~', $this->caddyfile(), $bound),
            'The image matcher no longer carries a numeric max_size.',
        );

        $services = Yaml::parseFile(\dirname(__DIR__, 3) . '/config/services.yaml', Yaml::PARSE_CUSTOM_TAGS);
        $this->assertIsArray($services);
        $parameters = $services['parameters'] ?? null;
        $this->assertIsArray($parameters);

        $this->assertSame($parameters['erpify.images.max_input_bytes'] ?? null, (int) $bound[1]);
    }

    /**
     * The image matcher accepts exactly the two public shapes and no near miss: a trailing slash, a suffix, the
     * owner's own update route, the front-controller or a prefixed spelling, another owner's suffix.
     */
    #[Test]
    public function theImageMatcherAcceptsTheTwoPlannedShapesAndNothingNear(): void
    {
        $shapes = [
            '/api/v1/backoffice/banks/{id}/logo',
            '/api/v1/backoffice/users/{id}/avatar',
        ];

        foreach ($this->caddyPatterns('imageWrite') as $site => $pattern) {
            foreach ($shapes as $shape) {
                $path = \str_replace('{id}', self::ID, $shape);

                $this->assertMatchesRegularExpression($pattern, $path, \sprintf('%s misses %s', $site, $path));

                foreach (
                    [
                        $path . '/',
                        $path . 's',
                        $path . '/more',
                        '/index.php' . $path,
                        '/prefix' . $path,
                        \strtoupper(\substr($path, 0, 4)) . \substr($path, 4),
                        \dirname($path),
                        \str_replace('/backoffice', '', $path),
                        \str_replace(self::ID, 'not-a-uuid', $path),
                    ] as $near
                ) {
                    $this->assertDoesNotMatchRegularExpression($pattern, $near, \sprintf('%s lifts %s', $site, $near));
                }
            }

            foreach (['banks/{id}/avatar', 'users/{id}/logo', 'bank-accounts/{id}/logo'] as $crossed) {
                $this->assertDoesNotMatchRegularExpression(
                    $pattern,
                    \str_replace('{id}', self::ID, '/api/v1/backoffice/' . $crossed),
                    $site,
                );
            }
        }
    }

    /**
     * Forward guard, by name: a `PUT` the router declares on `…/{id}/logo`, `…/{id}/avatar` or `…/{id}/image` is an
     * image write, so its public path must be one the matcher lifts the bound for — otherwise the edge cuts the body at
     * 1MB and the application sees a clean, shorter stream. The name-free guard over what a route carries is
     * `ImageWriteRouteAgreementGateTest`.
     */
    #[Test]
    public function everyImageWriteRouteTheRouterDeclaresIsOneTheMatcherLifts(): void
    {
        $manifest = \json_decode((string) \file_get_contents(\dirname(__DIR__, 3) . '/.route-manifest.json'), true);
        $this->assertIsArray($manifest);
        $patterns = $this->caddyPatterns('imageWrite');

        foreach ($manifest as $name => $route) {
            $this->assertIsArray($route);
            $path = $route['path'] ?? null;
            $methods = $route['methods'] ?? [];
            $this->assertIsString($path);
            $this->assertIsArray($methods);

            if (!\in_array('PUT', $methods, true) || 1 !== \preg_match('~/\{[^/}]+\}/(?:logo|avatar|image)$~', $path)) {
                continue;
            }

            foreach ($patterns as $site => $pattern) {
                $this->assertMatchesRegularExpression(
                    $pattern,
                    \preg_replace('~\{[^/}]+\}~', self::ID, $path) ?? $path,
                    \sprintf('%s does not lift the bound for the image write route %s', $site, (string) $name),
                );
            }
        }
    }

    /**
     * The 100 MiB bound is exactly the largest original, with no framing allowance.
     */
    #[Test]
    public function theUploadBoundIsTheLargestOriginal(): void
    {
        $this->assertSame(
            1,
            \preg_match('~request_body @documentUpload \{\s*max_size (\d+)\s*\}~', $this->caddyfile(), $bound),
            'The upload matcher no longer carries a numeric max_size.',
        );
        $this->assertSame(UploadLimits::MAX_ORIGINAL_BYTES, (int) $bound[1]);
    }

    /**
     * The session cookie the application issues in production: `framework.session.name` from the base block, its
     * `when@prod` override or a `config/packages/prod/` file, else `session.name` from the ini files the image
     * installs (`10-app.ini`, then `20-app.prod.ini`), else PHP's own default.
     */
    private function sessionCookieName(): string
    {
        $api = \dirname(__DIR__, 3);
        $name = null;

        foreach (
            [$api . '/config/packages/framework.yaml', $api . '/config/packages/prod/framework.yaml'] as $file
        ) {
            if (!\is_file($file)) {
                continue;
            }

            $config = Yaml::parseFile($file);
            $this->assertIsArray($config);

            $prod = $config['when@prod'] ?? null;
            $prodFramework = \is_array($prod) ? ($prod['framework'] ?? null) : null;

            foreach ([$config['framework'] ?? null, $prodFramework] as $framework) {
                $name = $this->sessionNameOf($framework) ?? $name;
            }
        }

        if (null !== $name) {
            return $name;
        }

        foreach (['10-app.ini', '20-app.prod.ini'] as $ini) {
            $values = \parse_ini_file($api . '/frankenphp/conf.d/' . $ini);
            $this->assertIsArray($values, $ini);
            $configured = $values['session.name'] ?? null;
            $name = \is_string($configured) && '' !== $configured ? $configured : $name;
        }

        return $name ?? self::PHP_DEFAULT_SESSION_NAME;
    }

    private function sessionNameOf(mixed $framework): ?string
    {
        $session = \is_array($framework) ? ($framework['session'] ?? null) : null;
        $configured = \is_array($session) ? ($session['name'] ?? null) : null;

        return \is_string($configured) && '' !== $configured ? $configured : null;
    }

    private function routerPath(): string
    {
        $manifest = \json_decode((string) \file_get_contents(\dirname(__DIR__, 3) . '/.route-manifest.json'), true);
        $this->assertIsArray($manifest, 'api/.route-manifest.json is not readable JSON.');
        $route = $manifest[UploadRoutes::CONTENT] ?? null;
        $this->assertIsArray($route, 'The content route is missing from api/.route-manifest.json.');
        $path = $route['path'] ?? null;
        $this->assertIsString($path);
        $this->assertStringContainsString('{id}', $path);

        return \str_replace('{id}', self::ID, $path);
    }

    private function caddyfile(): string
    {
        return (string) \file_get_contents(\dirname(__DIR__, 3) . '/frankenphp/Caddyfile');
    }

    /**
     * The path pattern of a lifting matcher and of the exclusion that mirrors it in `@boundedBody`, as PCRE.
     *
     * @return array<string, string>
     */
    private function caddyPatterns(string $matcher): array
    {
        $own = $this->patternIn($this->matcherBlock($matcher));
        $groups = \array_values(\array_filter(
            $this->exclusionGroups(),
            fn (string $group): bool => $this->patternIn($group) === $own,
        ));

        $this->assertCount(1, $groups, \sprintf('@boundedBody holds no single exclusion mirroring @%s.', $matcher));

        return [
            \sprintf('the Caddyfile @%s matcher', $matcher) => '~' . $own . '~',
            \sprintf('the bounded-body exclusion of @%s', $matcher) => '~' . $this->patternIn($groups[0]) . '~',
        ];
    }

    private function patternIn(string $block): string
    {
        $this->assertSame(
            1,
            \preg_match('~vars_regexp \{http\.request\.orig_uri\.path\} (\S+)~', $block, $pattern),
            'A matcher holds no path pattern.',
        );

        return $pattern[1];
    }

    /**
     * The body of a named matcher block of the Caddyfile.
     */
    private function matcherBlock(string $name): string
    {
        $this->assertSame(
            1,
            \preg_match('~\t\t@' . $name . ' \{\n((?:\t{3}[^\n]*\n)+)\t\t\}~', $this->caddyfile(), $block),
            \sprintf('The Caddyfile no longer declares @%s as a block.', $name),
        );

        return $block[1] ?? '';
    }

    /**
     * The `not { … }` groups of `@boundedBody`, each the exclusion of one lifting matcher.
     *
     * @return list<string>
     */
    private function exclusionGroups(): array
    {
        \preg_match_all('~\t{3}not \{\n((?:\t{4}[^\n]*\n)+)\t{3}\}~', $this->matcherBlock('boundedBody'), $groups);

        return $groups[1];
    }

    /**
     * @return array<string, string> the PWA whole-query rule, as PCRE (the API's is asked through its own method)
     */
    private function redactionPatterns(): array
    {
        $root = RepositoryRoot::path() ?? $this->fail(
            'The PWA redaction mirror is unreachable: restore the read-only `./` bind mount at /app/repo.',
        );
        $redaction = (string) \file_get_contents($root . '/pwa/src/context/shared/observability/domain/redaction.ts');
        $this->assertSame(
            1,
            \preg_match('~const WHOLE_QUERY_PATH =\s*/(.+)/i;~', $redaction, $pwa),
            'The PWA WHOLE_QUERY_PATH is no longer declared as a regular expression literal.',
        );

        return ['the PWA whole-query rule' => '~' . $pwa[1] . '~i'];
    }
}
