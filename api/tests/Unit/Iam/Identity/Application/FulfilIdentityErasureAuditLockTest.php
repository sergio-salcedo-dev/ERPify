<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use Erpify\Iam\Identity\Application\EraseIdentitySubject;
use Erpify\Iam\Identity\Application\FulfilIdentityErasure;
use Erpify\Iam\Invitation\Application\PurgeUserInvitations;
use Erpify\Iam\Session\Application\PurgeUserSessions;
use Erpify\Organization\Membership\Application\PurgeUserMembership;
use Erpify\Shared\Audit\Domain\ActorContext;
use Erpify\Shared\Audit\Infrastructure\Persistence\OrderedAuditSubjectTrailErasure;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use Erpify\Tests\Unit\Iam\Invitation\Application\InMemoryInvitationRepository;
use Erpify\Tests\Unit\Iam\Session\Application\InMemorySessionRepository;
use Erpify\Tests\Unit\Organization\Membership\Application\InMemoryMembershipRepository;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\FixedActorContextFactory;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\RecordingAuditActorAnonymiser;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\RecordingAuditLogger;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\RecordingAuditResourceAnonymiser;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\RecordingAuditSubjectRowLock;
use Erpify\Tests\Unit\Shared\Event\Infrastructure\Double\RecordingEventStoreSubjectAnonymiser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The concurrency link of the erasure chain, kept apart from the orchestration test the way the
 * event-store and reference-purge ones already are: it is its own claim, and folding it in would make a
 * failure say only that "the erasure changed".
 *
 * What it pins is an ORDER, not a call. `FulfilIdentityErasure` rewrites both audit axes as two statements
 * inside one transaction, and their row sets overlap between two subjects who acted on each other — with
 * `Row1 = (actor: A, resource: B)` and `Row2 = (actor: B, resource: A)`, erasing A takes Row1 then Row2 while
 * erasing B takes Row2 then Row1. Each statement matches a single row there, so ordering either one
 * internally changes nothing; only locking the union in a shared order breaks the cycle, and only if the
 * lock is taken while both statements are still ahead of it.
 *
 * That last clause is why the assertion reads the anonymiser count observed AT lock time. A test asserting
 * merely that `lock()` was called passes over the one arrangement the lock exists to forbid — a lock taken
 * after the first `UPDATE`, which orders nothing that has not already run.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversClass(FulfilIdentityErasure::class)]
final class FulfilIdentityErasureAuditLockTest extends TestCase
{
    private const string ACTING_ADMIN_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a90';

    public function testBothAuditAxesAreLockedAsOneSetBeforeEitherIsRewritten(): void
    {
        $actorAnonymiser = new RecordingAuditActorAnonymiser(matchCount: 1);
        $resourceAnonymiser = new RecordingAuditResourceAnonymiser(matchCount: 1);
        $rowLock = new RecordingAuditSubjectRowLock(
            static fn (): int => \count($actorAnonymiser->anonymisedActorIds) + \count($resourceAnonymiser->calls),
        );

        $this->useCase($actorAnonymiser, $resourceAnonymiser, $rowLock)->execute(UserMother::DEFAULT_ID);

        $this->assertSame([0], $rowLock->anonymisationsAlreadyRun);
    }

    public function testTheLockNamesTheSubjectBeingErasedAndIsTakenOnce(): void
    {
        // A lock over another person's rows would satisfy a call-count assertion and order nothing, and a
        // second lock inside one transaction would mean the union was assembled twice from two predicates —
        // which is the divergence the single set exists to prevent.
        $rowLock = new RecordingAuditSubjectRowLock();

        $this->useCase(
            new RecordingAuditActorAnonymiser(matchCount: 1),
            new RecordingAuditResourceAnonymiser(matchCount: 1),
            $rowLock,
        )->execute(UserMother::DEFAULT_ID);

        $this->assertCount(1, $rowLock->locked);
        $this->assertSame(FulfilIdentityErasure::SUBJECT_RESOURCE_TYPE, $rowLock->locked[0]->type);
        $this->assertSame(UserMother::DEFAULT_ID, $rowLock->locked[0]->id);
    }

    private function useCase(
        RecordingAuditActorAnonymiser $actorAnonymiser,
        RecordingAuditResourceAnonymiser $resourceAnonymiser,
        RecordingAuditSubjectRowLock $rowLock,
    ): FulfilIdentityErasure {
        return new FulfilIdentityErasure(
            new EraseIdentitySubject(
                new InMemoryUserRepository(UserMother::create()),
                new InMemoryPasswordResetTokenRepository(),
                new InMemoryRecoverySecretRepository(),
                new InlineTransactionManager(),
            ),
            new OrderedAuditSubjectTrailErasure(
                $rowLock,
                $actorAnonymiser,
                $resourceAnonymiser,
            ),
            new RecordingEventStoreSubjectAnonymiser(),
            new InMemoryActiveAdministratorDirectory([self::ACTING_ADMIN_ID => true]),
            new PurgeUserSessions(new InMemorySessionRepository()),
            new PurgeUserMembership(new InMemoryMembershipRepository()),
            new PurgeUserInvitations(new InMemoryInvitationRepository()),
            new RecordingAuditLogger(),
            new FixedActorContextFactory(ActorContext::forUser(self::ACTING_ADMIN_ID)),
            new InlineTransactionManager(),
        );
    }
}
