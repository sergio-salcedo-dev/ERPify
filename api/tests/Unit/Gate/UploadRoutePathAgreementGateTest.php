<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Documents\Document\Infrastructure\Http\UploadRoutes;
use Erpify\Shared\ErrorContract\Application\RequestUriRedaction;
use Erpify\Tests\Support\Dig;
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
 * @internal
 */
#[CoversNothing]
final class UploadRoutePathAgreementGateTest extends TestCase
{
    private const string ID = '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c';

    #[Test]
    public function everyPatternRecognisesTheRoutersPathAndNoNearMiss(): void
    {
        $path = $this->routerPath();
        $nearMisses = [$path . '/more', $path . 's', \str_replace('/content', '/contents', $path)];

        foreach ($this->patterns() as $site => $pattern) {
            $this->assertMatchesRegularExpression($pattern, $path, \sprintf('%s does not match %s', $site, $path));

            foreach ($nearMisses as $nearMiss) {
                $this->assertDoesNotMatchRegularExpression(
                    $pattern,
                    $nearMiss,
                    \sprintf('%s matches %s', $site, $nearMiss),
                );
            }
        }

        $this->assertTrue(RequestUriRedaction::withholdsWholeQuery($path), 'the API whole-query rule');

        foreach ($nearMisses as $nearMiss) {
            $this->assertFalse(RequestUriRedaction::withholdsWholeQuery($nearMiss), 'the API rule: ' . $nearMiss);
        }
    }

    /**
     * The 100 MiB bound is lifted only for a request carrying the session cookie, in both the matcher that lifts it
     * and the exclusion that keeps every other request at 1MB — dropped from either, an anonymous client could
     * hold a worker thread for as long as it trickles 100 MiB. Pinned against the cookie name the application
     * actually issues, so renaming the session cannot leave Caddy waiting for a cookie nobody sends.
     */
    #[Test]
    public function theUploadBoundIsLiftedOnlyForARequestCarryingTheSessionCookie(): void
    {
        $caddyfile = (string) \file_get_contents(\dirname(__DIR__, 3) . '/frankenphp/Caddyfile');
        $cookie = $this->sessionCookieName();

        foreach (['documentUpload', 'boundedBody'] as $matcher) {
            $this->assertSame(
                1,
                \preg_match('~@' . $matcher . ' \{\n(.*?)\n\t\t\}~s', $caddyfile, $block),
                \sprintf('@%s is no longer declared as a block in the Caddyfile.', $matcher),
            );
            $this->assertSame(
                1,
                \preg_match('~header_regexp Cookie (\S+)~', $block[1], $condition),
                \sprintf('@%s no longer requires a session cookie.', $matcher),
            );
            $pattern = '~' . $condition[1] . '~';

            $this->assertMatchesRegularExpression($pattern, $cookie . '=abc', $matcher);
            $this->assertMatchesRegularExpression($pattern, 'theme=dark; ' . $cookie . '=abc', $matcher);
            $this->assertDoesNotMatchRegularExpression($pattern, 'X' . $cookie . '=abc', $matcher);
            $this->assertDoesNotMatchRegularExpression($pattern, 'theme=dark', $matcher);
            $this->assertDoesNotMatchRegularExpression($pattern, $cookie . '=', $matcher);
        }
    }

    /**
     * The session cookie the application issues: `framework.session.name` when configured, PHP's `session.name`
     * otherwise — read here from the same ini files the server loads.
     */
    private function sessionCookieName(): string
    {
        $framework = Yaml::parseFile(\dirname(__DIR__, 3) . '/config/packages/framework.yaml');
        $this->assertIsArray($framework);
        $session = Dig::array($framework, 'framework', 'session');
        $configured = $session['name'] ?? null;

        if (\is_string($configured) && '' !== $configured) {
            return $configured;
        }

        $ini = (string) \ini_get('session.name');
        $this->assertNotSame('', $ini);

        return $ini;
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

    /**
     * @return array<string, string> each site's pattern as PCRE
     */
    private function patterns(): array
    {
        $caddyfile = (string) \file_get_contents(\dirname(__DIR__, 3) . '/frankenphp/Caddyfile');
        \preg_match_all('~vars_regexp \{http\.request\.orig_uri\.path\} (\S+)~', $caddyfile, $caddy);
        $this->assertCount(2, $caddy[1], 'The Caddyfile no longer holds exactly the two upload matchers.');

        $root = RepositoryRoot::path() ?? $this->fail(
            'The PWA redaction mirror is unreachable: restore the read-only `./` bind mount at /app/repo.',
        );
        $redaction = (string) \file_get_contents($root . '/pwa/src/context/shared/observability/domain/redaction.ts');
        $this->assertSame(
            1,
            \preg_match('~const WHOLE_QUERY_PATH =\s*/(.+)/i;~', $redaction, $pwa),
            'The PWA WHOLE_QUERY_PATH is no longer declared as a regular expression literal.',
        );

        return [
            'the Caddyfile upload matcher' => '~' . $caddy[1][0] . '~',
            'the Caddyfile bounded-body exclusion' => '~' . $caddy[1][1] . '~',
            'the PWA whole-query rule' => '~' . $pwa[1] . '~i',
        ];
    }
}
