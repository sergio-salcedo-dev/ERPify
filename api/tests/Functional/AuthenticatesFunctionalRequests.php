<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Iam\Identity\Domain\Email;
use Erpify\Iam\Identity\Domain\Enum\Role;
use Erpify\Iam\Identity\Domain\Repository\UserRepository;
use Erpify\Iam\Identity\Infrastructure\Security\SecurityUser;
use Erpify\Iam\Session\Domain\Entity\Session;
use Erpify\Iam\Session\Domain\Repository\SessionRepository;
use Erpify\Iam\Session\Domain\SessionId;
use Erpify\Tests\DataFixtures\UserFixtureFactory;
use RuntimeException;
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

    private const string FUNCTIONAL_SESSION_BAG_KEY = 'iamSessionId';

    /**
     * A stand-in organization id for the minted session's admission context — the session references it by id
     * with no physical FK, so it needs no seeded organization row.
     */
    private const string FUNCTIONAL_ORGANIZATION_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a50';

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
        $this->seatRegistrySession($client, $user->getId() ?? throw new RuntimeException('Functional user has no id.'));
    }

    /**
     * The Session Admission Gate requires a live registry session for every authenticated request; mint one for
     * the functional user and stash its correlation in the session the login seated the token in — the same
     * coupling real login's minting writes.
     */
    private function seatRegistrySession(KernelBrowser $client, string $userId): void
    {
        $container = static::getContainer();
        $sessions = $container->get(SessionRepository::class);
        self::assertInstanceOf(SessionRepository::class, $sessions);

        $sessionId = SessionId::generate();
        $session = Session::start(
            $sessionId->toString(),
            $userId,
            self::FUNCTIONAL_ORGANIZATION_ID,
            'Functional test client',
            '127.0.0.1',
            new DateTimeImmutable('+1 day'),
        );
        $session->pullDomainEvents();

        $sessions->save($session);

        $httpSession = $client->getSession();

        if ($httpSession instanceof \Symfony\Component\HttpFoundation\Session\SessionInterface) {
            $httpSession->set(self::FUNCTIONAL_SESSION_BAG_KEY, $sessionId->toString());
            $httpSession->save();
        }
    }
}
