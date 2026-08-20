<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Iam\Identity\Domain\Email;
use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Domain\Repository\UserRepository;
use Erpify\Organization\Membership\Domain\Entity\Membership;
use Erpify\Organization\Membership\Domain\Repository\MembershipRepository;
use Erpify\Organization\Organization\Domain\Entity\Organization;
use Erpify\Organization\Organization\Domain\Repository\OrganizationRepository;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\DataFixtures\UserFixtureFactory;

/**
 * An identity that can complete a REAL credential exchange over HTTP, which
 * {@see AuthenticatesFunctionalRequests} deliberately does not provide: that trait seats a session through
 * `loginUser`, so no authenticator runs and nothing the login path emits is observable.
 *
 * The membership is not scenery. Establishing a session resolves the caller's organization through it, so an
 * identity with no membership answers 503 at the door and the credential exchange never happens.
 *
 * **Seeded from a clean slate, not reused.** This suite shares one database with no rollback, so an
 * idempotent lookup-then-create looks safer and is not: a row already holding the id under another email
 * makes the email lookup miss and the insert collide on the primary key; a row whose password, status or
 * lock counter has drifted from what the caller expects wins the lookup for ever and no run self-heals; and
 * the two guards would key on different identities (email for the identity, id for the membership), so a
 * stale row can leave a membership granted to a user id nothing backs. The member is therefore dropped and
 * rewritten, and {@see forgetTheOrganizationMember} takes it away again — a functional test may not leave a
 * live credential behind, which is the convention every sibling in this suite already follows.
 *
 * The organization is the exception and is never deleted: it is the installation singleton other suites
 * provision and depend on, so this reuses whichever one is already there.
 */
trait SeedsAnOrganizationMember
{
    private function seedAnOrganizationMember(string $userId, string $email, string $password): void
    {
        $this->forgetTheOrganizationMember($userId, $email);

        $container = self::getContainer();

        $organizations = $container->get(OrganizationRepository::class);
        self::assertInstanceOf(OrganizationRepository::class, $organizations);

        if (!$organizations->findTheOne() instanceof Organization) {
            $organizations->save(Organization::provision(Uuid::generate(), 'Functional Member'));
        }

        $organization = $organizations->findTheOne();
        self::assertInstanceOf(Organization::class, $organization);
        $organizationId = $organization->getId();
        self::assertIsString($organizationId);

        $users = $container->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);

        $entityManager = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $users->save(UserFixtureFactory::create($userId, $email, $password));
        $entityManager->flush();

        $memberships = $container->get(MembershipRepository::class);
        self::assertInstanceOf(MembershipRepository::class, $memberships);
        $memberships->save(Membership::grant(Uuid::generate(), $userId, $organizationId));

        $entityManager->flush();
        $entityManager->clear();

        // The member has to be readable through the same ports the request will use, or the failure surfaces
        // as a 503 at the login route and points at the firewall instead of at the seed.
        self::assertInstanceOf(User::class, $users->findByEmail(Email::from($email)));
        self::assertInstanceOf(Membership::class, $memberships->findByUserId($userId));
    }

    /**
     * By id AND by email, because a stale row may match either alone. The membership goes first: it is what
     * points at the identity, and a membership surviving its member is a reference to nothing.
     */
    private function forgetTheOrganizationMember(string $userId, string $email): void
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        $connection->executeStatement(
            'DELETE FROM membership WHERE user_id = CAST(:userId AS UUID)',
            ['userId' => $userId],
        );
        $connection->executeStatement(
            'DELETE FROM identity_user WHERE id = CAST(:userId AS UUID) OR email = :email',
            ['userId' => $userId, 'email' => $email],
        );
    }
}
