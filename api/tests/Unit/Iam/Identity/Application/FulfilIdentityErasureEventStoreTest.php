<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use DateTimeImmutable;
use Erpify\Iam\Identity\Application\EraseIdentitySubject;
use Erpify\Iam\Identity\Application\FulfilIdentityErasure;
use Erpify\Iam\Identity\Domain\Entity\PasswordResetToken;
use Erpify\Iam\Invitation\Application\PurgeUserInvitations;
use Erpify\Iam\Session\Application\PurgeUserSessions;
use Erpify\Organization\Membership\Application\PurgeUserMembership;
use Erpify\Shared\Audit\Domain\ActorContext;
use Erpify\Shared\Audit\Infrastructure\Persistence\OrderedAuditSubjectTrailErasure;
use Erpify\Shared\Token\Domain\SingleUseToken;
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
 * The business-log axis of the erasure, kept apart from the orchestration test the way the reference-purge one
 * already is: three tables hold the subject's identifier and each is its own claim, so folding them into one
 * method makes a failure say only that "the erasure changed".
 *
 * Its object coupling is the erasure's own: building the use case means naming all eleven links of the single
 * atomic act it orchestrates, and a double for each. Both sibling tests suppress it for the same reason.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversClass(FulfilIdentityErasure::class)]
final class FulfilIdentityErasureEventStoreTest extends TestCase
{
    private const string TOKEN_ID = '0190e1f2-a3b4-7c5d-8e6f-1a2b3c4d5e41';

    private const string ACTING_ADMIN_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a90';

    /** A well-formed UUID that denotes no person — every id in this system comes from the same v7 generator. */
    private const string FOREIGN_AGGREGATE_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4bb0';

    public function testTheBusinessLogIsReachedWithTheSamePseudonymAsBothAuditAxes(): void
    {
        // One person must not end up as three anonymous identities across the three tables that held them, and
        // minting a second pseudonym is the mistake a caller can make with no other assertion noticing.
        $anonymiser = new RecordingAuditActorAnonymiser(matchCount: 3);
        $eventAnonymiser = new RecordingEventStoreSubjectAnonymiser(matchCount: 5);

        $result = $this->useCase($anonymiser, $eventAnonymiser)->execute(UserMother::DEFAULT_ID);

        $this->assertSame(
            [['subjectId' => UserMother::DEFAULT_ID, 'pseudonym' => $anonymiser->pseudonym]],
            $eventAnonymiser->calls,
        );
        // Its own count, independent of the two audit axes: three different row sets, so equal numbers would
        // hide an implementation that summed them.
        $this->assertSame(5, $result->anonymizedEventRows);
    }

    public function testTheBusinessLogIsNotReachedForAnIdentifierThatNeverDenotedALiveIdentity(): void
    {
        // The shape of a bank id pasted into the console: well formed, admissible everywhere a UUID is
        // admissible, and denoting nobody. Every other link asks a column that holds only person ids, so it
        // matches nothing and costs nothing; this one matches by value across every kind of aggregate, so
        // running it here would rewrite that bank's whole stream — irreversibly, and behind a 404.
        $eventAnonymiser = new RecordingEventStoreSubjectAnonymiser(matchCount: 5);

        $result = $this->useCaseWithoutTheSubject($eventAnonymiser)->execute(self::FOREIGN_AGGREGATE_ID);

        $this->assertSame([], $eventAnonymiser->calls);
        $this->assertSame(0, $result->anonymizedEventRows);
        // And the CLI's "nothing to erase" survives: business-log rows alone must not make it claim otherwise.
        $this->assertFalse($result->erasedAnything());
    }

    private function useCase(
        RecordingAuditActorAnonymiser $anonymiser,
        RecordingEventStoreSubjectAnonymiser $eventAnonymiser,
    ): FulfilIdentityErasure {
        return new FulfilIdentityErasure(
            new EraseIdentitySubject(
                new InMemoryUserRepository(UserMother::create()),
                new InMemoryPasswordResetTokenRepository($this->tokenFor(UserMother::DEFAULT_ID)),
                new InlineTransactionManager(),
            ),
            new OrderedAuditSubjectTrailErasure(
                new RecordingAuditSubjectRowLock(),
                $anonymiser,
                new RecordingAuditResourceAnonymiser(matchCount: 2),
            ),
            $eventAnonymiser,
            new InMemoryActiveAdministratorDirectory([self::ACTING_ADMIN_ID => true]),
            new PurgeUserSessions(new InMemorySessionRepository()),
            new PurgeUserMembership(new InMemoryMembershipRepository()),
            new PurgeUserInvitations(new InMemoryInvitationRepository()),
            new RecordingAuditLogger(),
            new FixedActorContextFactory(ActorContext::forUser(self::ACTING_ADMIN_ID)),
            new InlineTransactionManager(),
        );
    }

    private function useCaseWithoutTheSubject(
        RecordingEventStoreSubjectAnonymiser $eventAnonymiser,
    ): FulfilIdentityErasure {
        return new FulfilIdentityErasure(
            new EraseIdentitySubject(
                new InMemoryUserRepository(),
                new InMemoryPasswordResetTokenRepository(),
                new InlineTransactionManager(),
            ),
            new OrderedAuditSubjectTrailErasure(
                new RecordingAuditSubjectRowLock(),
                new RecordingAuditActorAnonymiser(matchCount: 0),
                new RecordingAuditResourceAnonymiser(matchCount: 0),
            ),
            $eventAnonymiser,
            new InMemoryActiveAdministratorDirectory([self::ACTING_ADMIN_ID => true]),
            new PurgeUserSessions(new InMemorySessionRepository()),
            new PurgeUserMembership(new InMemoryMembershipRepository()),
            new PurgeUserInvitations(new InMemoryInvitationRepository()),
            new RecordingAuditLogger(),
            new FixedActorContextFactory(ActorContext::forUser(self::ACTING_ADMIN_ID)),
            new InlineTransactionManager(),
        );
    }

    private function tokenFor(string $userId): PasswordResetToken
    {
        $generated = SingleUseToken::mint(new DateTimeImmutable('2026-07-14T13:00:00+00:00'));

        return PasswordResetToken::issue(self::TOKEN_ID, $userId, $generated->token);
    }
}
