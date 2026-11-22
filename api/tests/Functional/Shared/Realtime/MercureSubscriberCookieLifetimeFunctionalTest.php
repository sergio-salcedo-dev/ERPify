<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Realtime;

use Erpify\Tests\Functional\ResolvesContainerServices;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mercure\Authorization;
use Symfony\Component\Mercure\HubRegistry;

/**
 * The subscriber cookie the realtime authorize endpoints mint lives as long as `default_cookie_lifetime` in
 * `config/packages/mercure.yaml` says, and that value is the whole bound on how long a revoked or suspended
 * holder can open a NEW stream: the hub checks `exp` only when a subscription connects. Unset, the bundle
 * falls back to the session cookie lifetime (0 here) and issues a session cookie whose JWT lives an hour, with
 * every other gate green. The authorize controllers' unit tests build their own `Authorization`, so only the
 * container-built one can notice the setting going.
 *
 * Read through the `Authorization` the container builds, for a request on the hub's own public host so the
 * library's cookie-domain check does not interfere with what is measured.
 *
 * @internal
 */
#[CoversNothing]
final class MercureSubscriberCookieLifetimeFunctionalTest extends KernelTestCase
{
    use ResolvesContainerServices;

    private const int CONFIGURED_LIFETIME_SECONDS = 900;

    public function testTheSubscriberCookieExpiresWithinTheConfiguredLifetime(): void
    {
        self::bootKernel();

        $before = \time();
        $cookie = $this->mintCookie();

        $this->assertGreaterThan(
            $before,
            $cookie->getExpiresTime(),
            'a session cookie (expiry 0) is the unset default',
        );
        $this->assertLessThanOrEqual(
            \time() + self::CONFIGURED_LIFETIME_SECONDS,
            $cookie->getExpiresTime(),
            'the cookie outlives `default_cookie_lifetime`',
        );
    }

    public function testItsJwtExpiresWithinTheConfiguredLifetime(): void
    {
        self::bootKernel();

        $before = \time();
        $claims = $this->payload((string) $this->mintCookie()->getValue());

        $this->assertArrayHasKey('exp', $claims, 'a token without `exp` never expires at the hub');
        $this->assertIsInt($claims['exp']);
        $this->assertGreaterThan($before, $claims['exp']);
        $this->assertLessThanOrEqual(\time() + self::CONFIGURED_LIFETIME_SECONDS, $claims['exp']);
    }

    private function mintCookie(): Cookie
    {
        $publicUrl = $this->service(HubRegistry::class)->getHub()->getPublicUrl();

        return $this->service(Authorization::class)->createCookie(
            Request::create($publicUrl),
            ['urn:test:topic'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $jwt): array
    {
        $segments = \explode('.', $jwt);
        $this->assertCount(3, $segments, 'a JWT has three dot-separated segments');
        $base64 = \strtr($segments[1], '-_', '+/');
        $padded = $base64 . \str_repeat('=', (4 - \strlen($base64) % 4) % 4);
        $decoded = \json_decode((string) \base64_decode($padded, true), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
