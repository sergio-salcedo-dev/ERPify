<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Erpify\Iam\Identity\Domain\Email;
use Erpify\Iam\Identity\Domain\Enum\Role;
use Erpify\Iam\Identity\Domain\Repository\UserRepository;
use Erpify\Iam\Identity\Infrastructure\Security\SecurityUser;
use Erpify\Tests\DataFixtures\UserFixtureFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Uid\Uuid;

/**
 * Seats an authenticated session on a functional test client so its requests clear the default-deny firewall.
 * WebTestCase tests build their own data and never load the Alice fixture, so this ensures a dedicated user
 * exists and logs it in via `loginUser` — no HTTP round trip and no password verification. It carries
 * `AUDIT_READER` (the audit read routes) and `MANAGER` (the business tier granting bank read/write/delete),
 * so every permission-gated route these tests exercise is reachable.
 *
 * The lookup-then-create is idempotent because this suite manages its own isolation (manual TRUNCATE, no DAMA
 * rollback), so the `identity_user` row survives between tests — creating it once and reusing it keeps a second
 * `setUp` from colliding on the unique email. A stored row missing either role is replaced, so a user left
 * under-privileged never turns a gated route into a phantom 403.
 */
trait AuthenticatesFunctionalRequests
{
    private const string FUNCTIONAL_USER_EMAIL = 'functional@erpify.test';

    private function authenticateClient(KernelBrowser $client): void
    {
        $container = static::getContainer();

        $users = $container->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);

        $entityManager = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $user = $users->findByEmail(Email::from(self::FUNCTIONAL_USER_EMAIL));

        // The User aggregate has no role mutator by design, so a stored row missing either required role —
        // which would make a gated route answer 403 — is dropped and recreated rather than reused.
        $storedRolesAreSufficient = null !== $user
            && \in_array(Role::AUDIT_READER, $user->roles(), true)
            && \in_array(Role::MANAGER, $user->roles(), true);

        if (null !== $user && !$storedRolesAreSufficient) {
            $entityManager->remove($user);
            $entityManager->flush();
            $user = null;
        }

        if (null === $user) {
            $user = UserFixtureFactory::create(
                Uuid::v7()->toRfc4122(),
                self::FUNCTIONAL_USER_EMAIL,
                'functional-password',
                [Role::AUDIT_READER->value, Role::MANAGER->value],
            );

            $entityManager->persist($user);
            $entityManager->flush();
        }

        $client->loginUser(new SecurityUser($user), 'main');
    }
}
