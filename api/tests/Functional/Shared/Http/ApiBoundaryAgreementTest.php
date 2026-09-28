<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Http;

use Erpify\Shared\Http\Infrastructure\ApiRequestMatcher;
use Erpify\Tests\Functional\ResolvesContainerServices;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\AccessMapInterface;

/**
 * The firewall and the API-only listeners agree on where the API is. `access_control` is read from the
 * compiled container — the matchers the security bundle actually built, not the YAML it was built from —
 * and asked, probe by probe, whether any rule applies; the answer must be exactly {@see ApiRequestMatcher}'s.
 * A path the firewall authenticates but the matcher misses is a request that skips the session admission
 * gate, which is how a percent-encoded `/%61pi/…` admitted a revoked session.
 *
 * @internal
 */
#[CoversNothing]
final class ApiBoundaryAgreementTest extends KernelTestCase
{
    use ResolvesContainerServices;

    private const string AUTHENTICATED_FULLY = 'IS_AUTHENTICATED_FULLY';

    /**
     * Both sides of the boundary, plus the spellings where a raw-prefix check and the firewall once
     * disagreed: the bare mount point, a glued prefix, and percent-encoded forms the router decodes.
     * `/%2561pi` decodes once to `/%61pi`, which neither side (nor the router) decodes again.
     */
    private const array PROBES = [
        '/api/v1/me',
        '/api/v1/backoffice/users',
        '/api/v1/backoffice/login',
        '/api/v1/health',
        '/api/v1/health/database',
        '/api',
        '/api/',
        '/apiX',
        '/api-docs',
        '/%61pi/v1/me',
        '/%61%70%69/v1/me',
        '/%2561pi/v1/me',
        '/%61pi/v1/health',
        '/API/v1/me',
        '/',
        '/ap',
        '/x/api',
        '/.well-known/mercure',
        '/_wdt/x',
        '/_profiler/x',
        '/backoffice/banks',
    ];

    #[DataProvider('provideAccessControlAppliesExactlyWhereTheApiMatcherMatchesCases')]
    public function testAccessControlAppliesExactlyWhereTheApiMatcherMatches(string $path): void
    {
        $request = Request::create($path);
        [$attributes] = $this->accessMap()->getPatterns($request);
        $covered = null !== $attributes;
        $matched = $this->service(ApiRequestMatcher::class)->matches($request);

        $this->assertSame($covered, $matched, \sprintf(
            'access_control %s `%s`, ApiRequestMatcher %s it.',
            $covered ? 'covers' : 'skips',
            $path,
            $matched ? 'matches' : 'misses',
        ));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideAccessControlAppliesExactlyWhereTheApiMatcherMatchesCases(): iterable
    {
        foreach (self::PROBES as $path) {
            yield $path => [$path];
        }
    }

    public function testAPercentEncodedApiPathFallsThroughToTheCatchAll(): void
    {
        [$attributes] = $this->accessMap()->getPatterns(Request::create('/%61pi/v1/me'));

        $this->assertSame([self::AUTHENTICATED_FULLY], $attributes);
    }

    private function accessMap(): AccessMapInterface
    {
        $accessMap = self::getContainer()->get('security.access_map');
        $this->assertInstanceOf(AccessMapInterface::class, $accessMap);

        return $accessMap;
    }
}
