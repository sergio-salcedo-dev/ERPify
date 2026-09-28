<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\DataFixtures\Provider;

use Erpify\Iam\Identity\Infrastructure\Security\PasswordHasher;
use Erpify\Iam\Identity\Infrastructure\Security\SecurityUser;
use Erpify\Tests\DataFixtures\Provider\SeedCredentialProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactory;

/**
 * The seed's cost is taken from whatever the firewall's hasher is configured with, so it is pinned against a
 * configuration the tree does NOT use: at the test environment's own cost a seed hashing at a hard-coded value
 * would pass for the wrong reason.
 *
 * @internal
 */
#[CoversClass(SeedCredentialProvider::class)]
final class SeedCredentialProviderTest extends TestCase
{
    private const int CONFIGURED_COST = 5;

    public function testHashesTheSeedAtTheAlgorithmAndCostTheFirewallIsConfiguredWith(): void
    {
        $provider = new SeedCredentialProvider(new PasswordHasher(new PasswordHasherFactory([
            SecurityUser::class => ['algorithm' => 'bcrypt', 'cost' => self::CONFIGURED_COST],
        ])));

        $hash = $provider->seedPasswordHash('seed-password');

        $this->assertSame(
            ['algo' => PASSWORD_BCRYPT, 'algoName' => 'bcrypt', 'options' => ['cost' => self::CONFIGURED_COST]],
            \password_get_info($hash),
        );
        $this->assertTrue(\password_verify('seed-password', $hash));
    }
}
