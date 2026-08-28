<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use DateTimeImmutable;
use Erpify\Iam\Identity\Application\ChangeUserStatus;
use Erpify\Iam\Identity\Application\RevokeSessionsBestEffort;
use Erpify\Iam\Identity\Domain\Enum\IdentityStatus;
use Erpify\Iam\Identity\Domain\Exception\InvalidIdentityTransition;
use Erpify\Iam\Identity\Domain\Exception\LastActiveAdministratorProtected;
use Erpify\Iam\Identity\Domain\Exception\UserNotFound;
use Erpify\Iam\Session\Application\RevokeAllSessions;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use Erpify\Tests\Unit\Iam\Session\Application\InMemorySessionRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The status-change use case enforces the "keep ≥1 active ADMIN" invariant, commits the transition and then
 * revokes the identity's live sessions. The target's own roles are irrelevant — the
 * {@see \Erpify\Iam\Identity\Domain\Repository\ActiveAdministratorDirectory} is the sole authority on who
 * counts, so these tests drive that seam through its in-memory spec and observe the session teardown through a
 * real {@see RevokeSessionsBestEffort} over an in-memory session store.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversClass(ChangeUserStatus::class)]
final class ChangeUserStatusTest extends TestCase
{
    private const string OTHER_ADMIN_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a70';

    private const string GHOST_ADMIN_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a71';

    public function testSuspendingTheLastActiveAdministratorIsRejectedWithoutMutatingSavingOrRevoking(): void
    {
        $user = UserMother::create();
        $repository = new InMemoryUserRepository($user);
        $eventBus = new RecordingEventBus();
        $sessions = new InMemorySessionRepository();
        $directory = new InMemoryActiveAdministratorDirectory([UserMother::DEFAULT_ID => true]);

        try {
            $this->makeUseCase($repository, $directory, $eventBus, $sessions)->suspend(UserMother::DEFAULT_ID);
            $this->fail('Expected LastActiveAdministratorProtected.');
        } catch (LastActiveAdministratorProtected) {
            // rejected before any mutation, save, event or revoke
        }

        $this->assertSame(IdentityStatus::ACTIVE, $user->status());
        $this->assertSame([], $user->pullDomainEvents());
        $this->assertSame([], $repository->saved);
        $this->assertSame([], $eventBus->publishedEvents);
        $this->assertSame([], $sessions->revokeAllCalls);
    }

    /**
     * **The subject here is the DOUBLE, not the use case, and the name says so because the distinction is
     * the point.** `ChangeUserStatus` compares no ids at all — it calls `Uuid::ensure()`, which validates a
     * route id without normalising it, and then delegates the whole decision to the directory. So the only
     * edit that reds this case is one to {@see InMemoryActiveAdministratorDirectory}; deleting `strcasecmp`
     * from the production adapter leaves it green, and that invariant is pinned where it lives, by
     * `DoctrineActiveAdministratorDirectoryTest`.
     *
     * It earns its place anyway: the wire admits either casing, so a double comparing case-SENSITIVELY
     * answers `true` (permit) where the adapter answers `false` (409) for the same input — which would make
     * every other unit test in this file a green over the opposite answer, and no adapter test would notice.
     */
    public function testTheInMemoryDirectoryMatchesTheAdaptersCaseInsensitiveMembership(): void
    {
        $user = UserMother::create();
        $repository = new InMemoryUserRepository($user);
        $eventBus = new RecordingEventBus();
        $sessions = new InMemorySessionRepository();
        $directory = new InMemoryActiveAdministratorDirectory([\strtoupper(UserMother::DEFAULT_ID) => true]);

        try {
            $this->makeUseCase($repository, $directory, $eventBus, $sessions)->suspend(UserMother::DEFAULT_ID);
            $this->fail('Expected LastActiveAdministratorProtected.');
        } catch (LastActiveAdministratorProtected) {
            // the upper-case entry IS the target, so removing it drains the set
        }

        $this->assertSame(IdentityStatus::ACTIVE, $user->status());
        $this->assertSame([], $repository->saved);
        $this->assertSame([], $eventBus->publishedEvents);
    }

    public function testSuspendingAnAdminWhileOtherActiveAdminsRemainAppliesPublishesAndRevokes(): void
    {
        $user = UserMother::create();
        $repository = new InMemoryUserRepository($user);
        $eventBus = new RecordingEventBus();
        $sessions = new InMemorySessionRepository();
        $directory = new InMemoryActiveAdministratorDirectory([
            UserMother::DEFAULT_ID => true,
            self::OTHER_ADMIN_ID => true,
        ]);

        $changed = $this->makeUseCase($repository, $directory, $eventBus, $sessions)->suspend(UserMother::DEFAULT_ID);

        $this->assertSame(IdentityStatus::SUSPENDED, $changed->status());
        $this->assertSame([$user], $repository->saved);
        $this->assertCount(1, $eventBus->publishedEvents);
        $this->assertSame([UserMother::DEFAULT_ID], $sessions->revokeAllCalls);
    }

    public function testDeactivatingAnAdminWhileOtherActiveAdminsRemainAppliesPublishesAndRevokes(): void
    {
        $user = UserMother::create();
        $repository = new InMemoryUserRepository($user);
        $eventBus = new RecordingEventBus();
        $sessions = new InMemorySessionRepository();
        $directory = new InMemoryActiveAdministratorDirectory([
            UserMother::DEFAULT_ID => true,
            self::OTHER_ADMIN_ID => true,
        ]);

        $changed = $this->makeUseCase($repository, $directory, $eventBus, $sessions)
            ->deactivate(UserMother::DEFAULT_ID)
        ;

        $this->assertSame(IdentityStatus::DEACTIVATED, $changed->status());
        $this->assertCount(1, $eventBus->publishedEvents);
        $this->assertSame([UserMother::DEFAULT_ID], $sessions->revokeAllCalls);
    }

    public function testAPhantomAdminMembershipDoesNotRescueTheLastActiveAdministrator(): void
    {
        // A membership still marked ADMIN whose backing user was hard-deleted (or is no longer ACTIVE) must
        // NOT count — otherwise a ghost membership would let the last real administrator be deactivated.
        $user = UserMother::create();
        $repository = new InMemoryUserRepository($user);
        $eventBus = new RecordingEventBus();
        $sessions = new InMemorySessionRepository();
        $directory = new InMemoryActiveAdministratorDirectory([
            UserMother::DEFAULT_ID => true,
            self::GHOST_ADMIN_ID => false,
        ]);

        $this->expectException(LastActiveAdministratorProtected::class);

        $this->makeUseCase($repository, $directory, $eventBus, $sessions)->deactivate(UserMother::DEFAULT_ID);
    }

    public function testSuspendingAnIdentityOutsideTheActiveAdminSetIsNotRefusedAsTheLastAdministrator(): void
    {
        // No ACTIVE administrator exists at all — the only identity carrying the role is a phantom — and the
        // target is not one either. Removing an identity the set never held cannot empty it, so refusing here
        // would answer a plain viewer with a conflict naming an invariant they are no part of.
        $user = UserMother::create();
        $repository = new InMemoryUserRepository($user);
        $eventBus = new RecordingEventBus();
        $sessions = new InMemorySessionRepository();
        $directory = new InMemoryActiveAdministratorDirectory([self::GHOST_ADMIN_ID => false]);

        $changed = $this->makeUseCase($repository, $directory, $eventBus, $sessions)->suspend(UserMother::DEFAULT_ID);

        $this->assertSame(IdentityStatus::SUSPENDED, $changed->status());
        $this->assertSame([$user], $repository->saved);
        $this->assertCount(1, $eventBus->publishedEvents);
        $this->assertSame([UserMother::DEFAULT_ID], $sessions->revokeAllCalls);
        // The question is still put: this use case cannot know whether its target counts, and deciding that
        // here would take the directory's place as the sole authority on who does.
        $this->assertSame([UserMother::DEFAULT_ID], $directory->askedWithout);
    }

    public function testTheTargetIsReadUnderARowLock(): void
    {
        $repository = new InMemoryUserRepository(UserMother::create());
        $directory = new InMemoryActiveAdministratorDirectory([
            UserMother::DEFAULT_ID => true,
            self::OTHER_ADMIN_ID => true,
        ]);

        $this->makeUseCase($repository, $directory, new RecordingEventBus(), new InMemorySessionRepository())
            ->suspend(UserMother::DEFAULT_ID)
        ;

        // The directory locks the active-admin set, which never covers a target that is not an active
        // administrator — so the target carries its own lock on every path.
        $this->assertSame([UserMother::DEFAULT_ID], $repository->forUpdateCalls);
    }

    public function testATransitionLandingBeforeTheLockedReadIsRejectedInsteadOfOverwritten(): void
    {
        // The caller aimed at an identity it read as ACTIVE; a rival transition suspends it before the locked
        // re-read resolves. The state machine must judge the committed state, not the caller's snapshot —
        // otherwise DEACTIVATED lands on top of SUSPENDED and an event records a transition that never
        // legally occurred, onto a log that cannot be rewritten.
        $user = UserMother::create();
        $repository = new InMemoryUserRepository($user);
        $repository->onFindByIdForUpdate = static function () use ($user): void {
            $user->suspend();
            $user->pullDomainEvents();
        };
        $directory = new InMemoryActiveAdministratorDirectory([
            UserMother::DEFAULT_ID => true,
            self::OTHER_ADMIN_ID => true,
        ]);
        $eventBus = new RecordingEventBus();
        $sessions = new InMemorySessionRepository();

        $this->expectException(InvalidIdentityTransition::class);

        try {
            $this->makeUseCase($repository, $directory, $eventBus, $sessions)->deactivate(UserMother::DEFAULT_ID);
        } finally {
            $this->assertSame(IdentityStatus::SUSPENDED, $user->status());
            $this->assertSame([], $repository->saved);
            $this->assertSame([], $eventBus->publishedEvents);
            $this->assertSame([], $sessions->revokeAllCalls);
        }
    }

    public function testChangingTheStatusOfAMissingIdentityIsANotFound(): void
    {
        $repository = new InMemoryUserRepository();
        $eventBus = new RecordingEventBus();
        $sessions = new InMemorySessionRepository();
        $directory = new InMemoryActiveAdministratorDirectory([]);

        $this->expectException(UserNotFound::class);

        $this->makeUseCase($repository, $directory, $eventBus, $sessions)->suspend(UserMother::DEFAULT_ID);
    }

    private function makeUseCase(
        InMemoryUserRepository $repository,
        InMemoryActiveAdministratorDirectory $directory,
        RecordingEventBus $eventBus,
        InMemorySessionRepository $sessions,
    ): ChangeUserStatus {
        return new ChangeUserStatus(
            $repository,
            $directory,
            $this->revokeSessions($sessions),
            $eventBus,
            new InlineTransactionManager(),
        );
    }

    private function revokeSessions(InMemorySessionRepository $sessions): RevokeSessionsBestEffort
    {
        $clock = new FixedClock(new DateTimeImmutable('2026-07-18T12:00:00+00:00'));

        return new RevokeSessionsBestEffort(
            new RevokeAllSessions($sessions, new RecordingEventBus(), new InlineTransactionManager(), $clock),
            new NullLogger(),
        );
    }
}
