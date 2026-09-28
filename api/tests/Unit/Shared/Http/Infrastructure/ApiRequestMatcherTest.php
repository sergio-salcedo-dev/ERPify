<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Http\Infrastructure;

use Erpify\Shared\Http\Infrastructure\ApiRequestMatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * The API boundary, probed on the shapes where a raw-prefix check and the firewall's `^/api` disagreed:
 * the bare mount point, a prefix glued to another word, and a percent-encoded spelling the router decodes
 * before dispatching.
 *
 * @internal
 */
#[CoversClass(ApiRequestMatcher::class)]
final class ApiRequestMatcherTest extends TestCase
{
    #[DataProvider('provideItMatchesExactlyThePathsTheFirewallCatchAllCoversCases')]
    public function testItMatchesExactlyThePathsTheFirewallCatchAllCovers(string $path, bool $expected): void
    {
        $this->assertSame($expected, (new ApiRequestMatcher())->matches(Request::create($path)));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function provideItMatchesExactlyThePathsTheFirewallCatchAllCoversCases(): iterable
    {
        yield 'a versioned API route' => ['/api/v1/me', true];
        yield 'the bare mount point' => ['/api', true];
        yield 'the mount point with a trailing slash' => ['/api/', true];
        yield 'a prefix glued to a word' => ['/apiX', true];
        yield 'a hyphenated sibling' => ['/api-docs', true];
        yield 'a percent-encoded first letter' => ['/%61pi/v1/me', true];
        yield 'a fully percent-encoded prefix' => ['/%61%70%69/v1/me', true];
        yield 'a double-encoded prefix, decoded once only' => ['/%2561pi/v1/me', false];
        yield 'an upper-cased prefix, case-sensitive like the firewall and router' => ['/API/v1/me', false];
        yield 'the site root' => ['/', false];
        yield 'a shorter prefix' => ['/ap', false];
        yield 'the Mercure hub' => ['/.well-known/mercure', false];
        yield 'the web debug toolbar' => ['/_wdt/x', false];
        yield 'api as a later segment' => ['/x/api', false];
    }
}
