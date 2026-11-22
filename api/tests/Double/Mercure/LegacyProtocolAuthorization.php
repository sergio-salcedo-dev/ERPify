<?php

declare(strict_types=1);

namespace Erpify\Tests\Double\Mercure;

use Symfony\Component\Mercure\Authorization;
use Symfony\Component\Mercure\Hub;
use Symfony\Component\Mercure\HubRegistry;
use Symfony\Component\Mercure\Jwt\LcobucciFactory;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\ProtocolVersion;

/**
 * A real `Authorization` over a hub pinned to the 0.x protocol the application configures
 * (`config/packages/mercure.yaml`); the library defaults to 1.0, a different token shape and cookie name.
 *
 * @internal
 */
final class LegacyProtocolAuthorization
{
    private const string HUB_URL = 'https://localhost/.well-known/mercure';

    private const string JWT_SECRET = 'test-secret-not-a-real-credential-0123456789abcdef';

    public static function create(): Authorization
    {
        return new Authorization(new HubRegistry(new Hub(
            self::HUB_URL,
            new StaticTokenProvider('unused'),
            new LcobucciFactory(self::JWT_SECRET, protocolVersion: ProtocolVersion::Legacy),
            protocolVersion: ProtocolVersion::Legacy,
        )));
    }
}
