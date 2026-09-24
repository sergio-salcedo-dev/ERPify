<?php

declare(strict_types=1);

namespace Erpify\Tests\DataFixtures\Provider;

use Erpify\Iam\Identity\Infrastructure\Security\PasswordHasher;
use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Alice function `<seedPasswordHash("…")>`: hashes a seed plaintext with the hasher the firewall configures for
 * the running environment, never with an algorithm or cost chosen here.
 *
 * **The cost is the security property, not the speed.** An unknown address pays a login's timing floor as one
 * verification by that same configured hasher, while a known one pays a verification whose cost is read from
 * its STORED hash. A seed minted cheaper than the configuration therefore answers a wrong password faster than
 * an address that does not exist, and on any environment loaded from these fixtures that difference is the
 * whole existence signal — an order of magnitude, not a statistical margin. Hashing through the configured
 * hasher makes the two costs equal by construction in every environment, at the price of a production-cost hash
 * per seeded identity when the dev fixtures load.
 *
 * It is a Faker provider rather than a step inside {@see \Erpify\Tests\DataFixtures\UserFixtureFactory} because
 * Alice calls a `__factory` statically, with no container to resolve the hasher from; a provider is a service,
 * and its output reaches the factory as an ordinary argument.
 */
#[AutoconfigureTag('nelmio_alice.faker.provider')]
final readonly class SeedCredentialProvider
{
    public function __construct(private PasswordHasher $passwordHasher)
    {
    }

    public function seedPasswordHash(#[SensitiveParameter] string $plainPassword): string
    {
        return $this->passwordHasher->hash($plainPassword);
    }
}
