<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Realtime;

use Erpify\Tests\Functional\ResolvesContainerServices;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mercure\HubRegistry;
use Symfony\Component\Mercure\Jwt\Grant;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Mercure\ProtocolVersion;

/**
 * The hub the container builds speaks the protocol of the hub it talks to. That hub is the one embedded in
 * the FrankenPHP image, a 1.x release run in compatibility mode for the 0.x protocol (`frankenphp/Caddyfile`),
 * while the bundle defaults to 1.0 — so the version comes from `config/packages/mercure.yaml` alone. Losing it
 * changes the token shape and the cookie name the browser receives, and a hub in that mode accepts a 1.0 token
 * or rejects it depending on its `aud`, so the break would be partial and silent rather than loud. The unit
 * tests of the authorize controllers build their own hub and the acceptance lane swaps in a recording double,
 * so neither would notice the pin going.
 *
 * Read through {@see HubRegistry}, which is what `Authorization` and the publishers resolve, rather than
 * through `HubInterface`, which the test environment aliases to that double.
 *
 * @internal
 */
#[CoversNothing]
final class MercureHubProtocolFunctionalTest extends KernelTestCase
{
    use ResolvesContainerServices;

    public function testTheConfiguredHubSpeaksTheLegacyProtocolOfTheEmbeddedHub(): void
    {
        self::bootKernel();

        $hub = $this->service(HubRegistry::class)->getHub();

        $this->assertSame(ProtocolVersion::Legacy, $hub->getProtocolVersion());
        $this->assertSame('mercureAuthorization', $hub->getCookieName());
    }

    public function testItsTokensCarryTheLegacyMercureClaim(): void
    {
        self::bootKernel();

        $factory = $this->service(HubRegistry::class)->getHub()->getFactory();
        $this->assertInstanceOf(
            TokenFactoryInterface::class,
            $factory,
            'the hub is configured with a JWT secret, so it mints tokens',
        );

        $claims = $this->payload($factory->create([new Grant([Grant::ACTION_SUBSCRIBE], ['urn:test:topic'])]));

        $this->assertArrayHasKey('mercure', $claims, 'the 0.x protocol authorises from the `mercure` claim');
        $this->assertArrayNotHasKey(
            'authorization_details',
            $claims,
            'the protocol 1.0 claim this application does not speak',
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
