<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use ArrayObject;
use Erpify\Iam\Identity\Application\EraseIdentitySubject;
use Erpify\Iam\Identity\Application\FulfilIdentityErasure;
use Erpify\Iam\Invitation\Application\PurgeUserInvitations;
use Erpify\Iam\Session\Application\PurgeUserSessions;
use Erpify\Organization\Membership\Application\PurgeUserMembership;
use Erpify\Shared\Audit\Domain\ActorContext;
use Erpify\Shared\Audit\Infrastructure\Persistence\OrderedAuditSubjectTrailErasure;
use Erpify\Shared\Persistence\Application\TransactionManager;
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
 * The erasure's last link: scheduling the re-sweep that repeats its passes for the hour after it commits. Kept
 * apart from the orchestration test like the event-store and reference-purge ones, so a failure names this
 * claim rather than "the erasure changed".
 *
 * Its object coupling is the erasure's own, as for both siblings.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversClass(FulfilIdentityErasure::class)]
final class FulfilIdentityErasureResweepSchedulingTest extends TestCase
{
    private const string ACTING_ADMIN_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a90';

    public function testALiveSubjectIsScheduledForTheResweepThatCatchesWhatTheChainCannotReachYet(): void
    {
        $resweeps = new InMemoryErasureResweepRepository();

        $this->useCase(new InMemoryUserRepository(UserMother::create()), $resweeps)->execute(UserMother::DEFAULT_ID);

        $this->assertSame([UserMother::DEFAULT_ID], $resweeps->scheduledSubjectIds());
    }

    public function testAnUnknownSubjectIsNeverScheduledBecauseTheResweepMatchesByValueAcrossEveryAggregate(): void
    {
        // The re-sweep repeats the business-log pass, which would rewrite any aggregate's stream sharing the id,
        // so it may only ever be handed an id the erasure has just shown to denote a person.
        $resweeps = new InMemoryErasureResweepRepository();

        $this->useCase(new InMemoryUserRepository(), $resweeps)->execute(UserMother::DEFAULT_ID);

        $this->assertSame([], $resweeps->scheduledSubjectIds());
    }

    public function testTheResweepIsScheduledInsideTheErasuresTransaction(): void
    {
        // Outside it, a crash between the erasure's commit and the schedule would lose the re-sweep while the
        // erasure stood, and a rolled-back erasure could leave a pending row naming a subject still alive.
        $transactions = new DepthRecordingTransactionManager();
        $resweeps = new InMemoryErasureResweepRepository();
        /** @var ArrayObject<int, int> $depthsAtSave */
        $depthsAtSave = new ArrayObject();
        $resweeps->onSave = static function () use ($transactions, $depthsAtSave): void {
            $depthsAtSave[] = $transactions->depth;
        };

        $this->useCase(new InMemoryUserRepository(UserMother::create()), $resweeps, $transactions)
            ->execute(UserMother::DEFAULT_ID)
        ;

        $this->assertSame([1], $depthsAtSave->getArrayCopy());
    }

    private function useCase(
        InMemoryUserRepository $users,
        InMemoryErasureResweepRepository $resweeps,
        ?TransactionManager $transactions = null,
    ): FulfilIdentityErasure {
        return new FulfilIdentityErasure(
            new EraseIdentitySubject(
                $users,
                new InMemoryPasswordResetTokenRepository(),
                new InMemoryRecoverySecretRepository(),
                new InlineTransactionManager(),
            ),
            new OrderedAuditSubjectTrailErasure(
                new RecordingAuditSubjectRowLock(),
                new RecordingAuditActorAnonymiser(matchCount: 0),
                new RecordingAuditResourceAnonymiser(matchCount: 0),
            ),
            new RecordingEventStoreSubjectAnonymiser(),
            new InMemoryActiveAdministratorDirectory([self::ACTING_ADMIN_ID => true]),
            new PurgeUserSessions(new InMemorySessionRepository()),
            new PurgeUserMembership(new InMemoryMembershipRepository()),
            new PurgeUserInvitations(new InMemoryInvitationRepository()),
            new RecordingAuditLogger(),
            new FixedActorContextFactory(ActorContext::forUser(self::ACTING_ADMIN_ID)),
            $transactions ?? new InlineTransactionManager(),
            $resweeps,
        );
    }
}
