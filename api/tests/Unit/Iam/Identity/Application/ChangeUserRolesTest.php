<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use DateTimeImmutable;
use Erpify\Iam\Identity\Application\ChangeUserRoles;
use Erpify\Iam\Identity\Application\RevokeSessionsBestEffort;
use Erpify\Iam\Identity\Domain\Exception\LastActiveAdministratorProtected;
use Erpify\Iam\Identity\Domain\Exception\UserNotFound;
use Erpify\Iam\Session\Application\RevokeAllSessions;
use Erpify\Shared\Access\Domain\Role;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use Erpify\Tests\Unit\Iam\Session\Application\InMemorySessionRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The role-assignment use case diverges from its status-change sibling in two ways worth pinning: it asks the
 * {@see \Erpify\Iam\Identity\Domain\Repository\ActiveAdministratorDirectory} only when the change would demote
 * an active administrator, and it treats a redundant set as a no-op instead of a conflict. Both are observed
 * here through hand-written doubles — the directory records whether it was consulted at all, and the session
 * teardown is observed through a real {@see RevokeSessionsBestEffort} over an in-memory session store.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversClass(ChangeUserRoles::class)]
final class ChangeUserRolesTest extends TestCase
{
    private const string OTHER_ADMIN_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a70';

    public function testReplacingTheSetSavesPublishesAndRevokesSessions(): void
    {
        $user = UserMother::create(roles: [Role::VIEWER]);
        $repository = new InMemoryUserRepository($user);
        $eventBus = new RecordingEventBus();
        $sessions = new InMemorySessionRepository();
        $directory = new InMemoryActiveAdministratorDirectory([]);

        $changed = $this->makeUseCase($repository, $directory, $eventBus, $sessions)
            ->run(UserMother::DEFAULT_ID, Role::EDITOR, Role::MANAGER)
        ;

        $this->assertSame([Role::EDITOR, Role::MANAGER], $changed->roles());
        $this->assertSame([$user], $repository->saved);
        $this->assertCount(1, $eventBus->publishedEvents);
        $this->assertSame([UserMother::DEFAULT_ID], $sessions->revokeAllCalls);
    }

    public function testEditingANonAdministratorNeverConsultsTheAdministratorDirectory(): void
    {
        $user = UserMother::create(roles: [Role::VIEWER]);
        $directory = new InMemoryActiveAdministratorDirectory([]);

        $this->makeUseCase(new InMemoryUserRepository($user), $directory)
            ->run(UserMother::DEFAULT_ID, Role::EDITOR)
        ;

        $this->assertSame([], $directory->askedWithout);
    }

    public function testWideningTheSoleAdministratorsRolesIsAllowedAndSkipsTheGuard(): void
    {
        // The directory holds this user as the ONLY active administrator, so an unconditional guard would
        // refuse here. Keeping ADMIN removes nobody from the pool, so the question is never put.
        $user = UserMother::create(roles: [Role::ADMIN]);
        $directory = new InMemoryActiveAdministratorDirectory([UserMother::DEFAULT_ID => true]);

        $changed = $this->makeUseCase(new InMemoryUserRepository($user), $directory)
            ->run(UserMother::DEFAULT_ID, Role::ADMIN, Role::EDITOR)
        ;

        $this->assertSame([Role::ADMIN, Role::EDITOR], $changed->roles());
        $this->assertSame([], $directory->askedWithout);
    }

    public function testDemotingANonActiveAdministratorSkipsTheGuard(): void
    {
        // A suspended identity is not in the active-administrator pool, so dropping its ADMIN drains nothing.
        $user = UserMother::create(roles: [Role::ADMIN]);
        $user->suspend();
        $user->pullDomainEvents();

        $directory = new InMemoryActiveAdministratorDirectory([UserMother::DEFAULT_ID => true]);

        $changed = $this->makeUseCase(new InMemoryUserRepository($user), $directory)
            ->run(UserMother::DEFAULT_ID, Role::VIEWER)
        ;

        $this->assertSame([Role::VIEWER], $changed->roles());
        $this->assertSame([], $directory->askedWithout);
    }

    public function testDemotingTheLastActiveAdministratorIsRejectedWithoutMutatingSavingOrRevoking(): void
    {
        $user = UserMother::create(roles: [Role::ADMIN]);
        $repository = new InMemoryUserRepository($user);
        $eventBus = new RecordingEventBus();
        $sessions = new InMemorySessionRepository();
        $directory = new InMemoryActiveAdministratorDirectory([UserMother::DEFAULT_ID => true]);

        try {
            $this->makeUseCase($repository, $directory, $eventBus, $sessions)
                ->run(UserMother::DEFAULT_ID, Role::EDITOR)
            ;
            $this->fail('Expected LastActiveAdministratorProtected.');
        } catch (LastActiveAdministratorProtected) {
            // rejected before any mutation, save, event or revoke
        }

        $this->assertSame([UserMother::DEFAULT_ID], $directory->askedWithout);
        $this->assertSame([Role::ADMIN], $user->roles());
        $this->assertSame([], $user->pullDomainEvents());
        $this->assertSame([], $repository->saved);
        $this->assertSame([], $eventBus->publishedEvents);
        $this->assertSame([], $sessions->revokeAllCalls);
    }

    public function testDemotingAnAdministratorWhileOthersRemainIsAllowed(): void
    {
        $user = UserMother::create(roles: [Role::ADMIN]);
        $directory = new InMemoryActiveAdministratorDirectory([
            UserMother::DEFAULT_ID => true,
            self::OTHER_ADMIN_ID => true,
        ]);

        $changed = $this->makeUseCase(new InMemoryUserRepository($user), $directory)
            ->run(UserMother::DEFAULT_ID, Role::EDITOR)
        ;

        $this->assertSame([Role::EDITOR], $changed->roles());
        $this->assertSame([UserMother::DEFAULT_ID], $directory->askedWithout);
    }

    public function testResendingTheSameSetInAnotherOrderChangesNothing(): void
    {
        $user = UserMother::create(roles: [Role::MANAGER, Role::AUDIT_READER]);
        $repository = new InMemoryUserRepository($user);
        $eventBus = new RecordingEventBus();
        $sessions = new InMemorySessionRepository();
        $directory = new InMemoryActiveAdministratorDirectory([]);

        $unchanged = $this->makeUseCase($repository, $directory, $eventBus, $sessions)
            ->run(UserMother::DEFAULT_ID, Role::AUDIT_READER, Role::MANAGER, Role::MANAGER)
        ;

        $this->assertSame([Role::MANAGER, Role::AUDIT_READER], $unchanged->roles());
        $this->assertSame([], $repository->saved);
        $this->assertSame([], $eventBus->publishedEvents);
        // The point of the short-circuit: a redundant save must not tear down live sessions.
        $this->assertSame([], $sessions->revokeAllCalls);
    }

    public function testChangingTheRolesOfAMissingIdentityIsANotFound(): void
    {
        $this->expectException(UserNotFound::class);

        $this->makeUseCase(new InMemoryUserRepository(), new InMemoryActiveAdministratorDirectory([]))
            ->run(UserMother::DEFAULT_ID, Role::EDITOR)
        ;
    }

    private function makeUseCase(
        InMemoryUserRepository $repository,
        InMemoryActiveAdministratorDirectory $directory,
        ?RecordingEventBus $eventBus = null,
        ?InMemorySessionRepository $sessions = null,
    ): ChangeUserRoles {
        return new ChangeUserRoles(
            $repository,
            $directory,
            $this->revokeSessions($sessions ?? new InMemorySessionRepository()),
            $eventBus ?? new RecordingEventBus(),
            new InlineTransactionManager(),
        );
    }

    private function revokeSessions(InMemorySessionRepository $sessions): RevokeSessionsBestEffort
    {
        $clock = new FixedClock(new DateTimeImmutable('2026-07-19T12:00:00+00:00'));

        return new RevokeSessionsBestEffort(
            new RevokeAllSessions($sessions, new RecordingEventBus(), new InlineTransactionManager(), $clock),
            new NullLogger(),
        );
    }
}
