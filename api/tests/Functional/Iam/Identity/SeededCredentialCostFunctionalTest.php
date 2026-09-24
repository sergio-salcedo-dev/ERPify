<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Iam\Identity;

use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Infrastructure\Security\PasswordHashingTimingFloor;
use Erpify\Iam\Identity\Infrastructure\Security\SecurityUser;
use Erpify\Tests\DataFixtures\Provider\SeedCredentialProvider;
use Erpify\Tests\DataFixtures\UserFixtureFactory;
use PHPUnit\Framework\Attributes\CoversNothing;
use ReflectionProperty;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * A known address pays a verification whose cost is read from its STORED hash; an unknown one pays the timing
 * floor, one verification of the floor's own dummy. The two are only indistinguishable while those hashes cost
 * the same, so this compares them as the container actually builds them, against the firewall's configured
 * hasher: the floor's dummy, a seed minted through the Alice provider, and a seed minted by the kernel-free
 * factory entry point that functional tests use.
 *
 * Reading the floor's dummy through reflection is deliberate. Its unit test proves the floor ASKS the factory
 * for the firewall's hasher; only the real container can prove what that request returns.
 *
 * @internal
 */
#[CoversNothing]
final class SeededCredentialCostFunctionalTest extends KernelTestCase
{
    public function testTheTimingFloorAndEverySeededCredentialCostWhatTheConfiguredHasherCosts(): void
    {
        $container = self::getContainer();
        $hasherFactory = $container->get(PasswordHasherFactoryInterface::class);
        $this->assertInstanceOf(PasswordHasherFactoryInterface::class, $hasherFactory);
        $configured = \password_get_info(
            $hasherFactory->getPasswordHasher(SecurityUser::class)->hash('configured-probe'),
        );

        $floor = $container->get(PasswordHashingTimingFloor::class);
        $this->assertInstanceOf(PasswordHashingTimingFloor::class, $floor);
        $floor->equalise();
        $dummyHash = new ReflectionProperty(PasswordHashingTimingFloor::class, 'dummyHash');
        $floorHash = $dummyHash->getValue($floor);
        $this->assertIsString($floorHash);

        $seedProvider = $container->get(SeedCredentialProvider::class);
        $this->assertInstanceOf(SeedCredentialProvider::class, $seedProvider);

        $kernelFreeSeed = UserFixtureFactory::create(
            '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4b01',
            'seeded-cost@erpify.test',
            'seed-password',
        )->passwordHash()?->toString();
        $this->assertIsString($kernelFreeSeed);

        $this->assertSame($configured, \password_get_info($floorHash), 'the timing floor');
        $this->assertSame($configured, \password_get_info($seedProvider->seedPasswordHash('seed')), 'the Alice seed');
        $this->assertSame($configured, \password_get_info($kernelFreeSeed), 'the kernel-free factory seed');
    }

    /**
     * The cost comparison above cannot see WHICH entry point the seed file calls: the test environment
     * configures the same cost the kernel-free factory hard-codes, so a seed routed back through the plaintext
     * entry point would pass it here and ship the cheaper hash to every environment configured above that cost.
     * What decides the dev seed is therefore the file's own wiring, read as data: every credentialed identity is
     * built from a hash the `seedPasswordHash` provider minted.
     */
    public function testEveryCredentialedSeedIsHashedByTheConfiguredHasher(): void
    {
        $seed = Yaml::parseFile(\dirname(__DIR__, 3) . '/DataFixtures/Fixtures/User.yaml');
        $this->assertIsArray($seed);
        $users = $seed[User::class] ?? null;
        $this->assertIsArray($users);
        $this->assertNotEmpty($users);
        $credentialed = 0;

        foreach ($users as $reference => $definition) {
            $this->assertIsArray($definition);
            $factory = $definition['__factory'] ?? null;
            $this->assertIsArray($factory);
            $this->assertSame(
                [UserFixtureFactory::class . '::createWithPasswordHash'],
                \array_keys($factory),
                (string) $reference,
            );
            $arguments = \reset($factory);
            $this->assertIsArray($arguments);

            if (\in_array($arguments[4] ?? 'ACTIVE', ['INVITED', 'REVOKED'], true)) {
                continue;
            }

            ++$credentialed;
            $this->assertIsString($arguments[2] ?? null, (string) $reference);
            $this->assertMatchesRegularExpression(
                '/^<seedPasswordHash\("[^"]+"\)>$/',
                $arguments[2],
                (string) $reference,
            );
        }

        $this->assertGreaterThan(0, $credentialed, 'the walk reached no credentialed seed, so it proved nothing');
    }
}
