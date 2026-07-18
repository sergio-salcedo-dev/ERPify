<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use DateTimeImmutable;
use Erpify\Iam\Identity\Application\ChangeUserStatus;
use Erpify\Iam\Identity\Application\RevokeSessionsBestEffort;
use Erpify\Iam\Identity\Domain\Enum\IdentityStatus;
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
