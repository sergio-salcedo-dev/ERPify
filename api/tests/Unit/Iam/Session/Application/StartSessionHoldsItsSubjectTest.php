<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Session\Application;

use Erpify\Iam\Session\Application\StartSession;
use Erpify\Iam\Session\Domain\Entity\Session;
use Erpify\Iam\Session\Domain\Enum\SessionStatus;
use Erpify\Iam\Session\Domain\Event\SessionRevoked;
use Erpify\Iam\Session\Domain\Exception\SessionSubjectNotAdmissible;
use Erpify\Iam\Session\Domain\SessionId;
use Erpify\Iam\Session\Domain\SubjectAdmission;
use Erpify\Shared\Clock\Domain\SystemClock;
use Erpify\Tests\Double\Clock\FixedClock;
use Erpify\Tests\Unit\Iam\Session\Domain\Entity\Mother\SessionMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A login holds the identity it mints a session for, inside the unit of work and ahead of every session row, so it
 * lands on one side of that identity's erasure or status change. The real lock and the writes it waits on are
 * proved against Postgres in `LoginRacingAnErasureFunctionalTest` and `LoginRacingASuspensionFunctionalTest`;
 * this pins the use case's half — which hold is taken, where, and what a refused mint leaves behind.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") — the use case's whole unit of work, observed through the
 * doubles of each port it touches.
 */
#[CoversClass(StartSession::class)]
final class StartSessionHoldsItsSubjectTest extends TestCase
{
    public function testTheIdentityIsHeldForAdmissionInsideTheUnitOfWorkBeforeAnySessionIsWritten(): void
    {
        $previous = SessionMother::active();
        $previous->pullDomainEvents();

        $sessions = new InMemorySessionRepository($previous);
        $eventBus = new RecordingEventBus();
        $transactions = new TransactionSnapshottingManager($sessions, $eventBus);
        $lock = new RecordingSessionSubjectLock($sessions, $transactions);

        $this->startSession($sessions, $eventBus, $transactions, $lock, new RecordingCurrentSessionReference(
            SessionId::fromString(SessionMother::DEFAULT_ID),
        ))->start(
            SessionMother::DEFAULT_USER_ID,
            SessionMother::DEFAULT_ORG_ID,
            SessionMother::DEFAULT_DEVICE,
            null,
        );

        $this->assertSame(
            [[SessionMother::DEFAULT_USER_ID, 0, true, 'holdForAdmission']],
            $lock->calls,
            'a login holds admission, never mere existence, and before writing any session',
        );
        $this->assertSame(SessionStatus::REVOKED, $previous->status(), 'and the login then went ahead');
    }

    /**
     * Arriving second, behind a write that walled or erased the identity: no session is minted and no
     * correlation is written — but the session the cookie carried is still retired, and that retirement
     * commits. The caller drops the native session on any failure, so the row would otherwise be left `ACTIVE`
     * with no bearer, the same reason the successful login revokes it.
     */
    #[DataProvider('provideARefusedMintRetiresTheCarriedSessionAndRaisesItsReasonCases')]
    public function testARefusedMintRetiresTheCarriedSessionAndRaisesItsReason(SubjectAdmission $reason): void
    {
        $previous = SessionMother::active();
        $previous->pullDomainEvents();

        $sessions = new InMemorySessionRepository($previous);
        $eventBus = new RecordingEventBus();
        $transactions = new TransactionSnapshottingManager($sessions, $eventBus);
        $currentSession = new RecordingCurrentSessionReference(SessionId::fromString(SessionMother::DEFAULT_ID));
        $walled = new RecordingSessionSubjectLock();
        $walled->admission = $reason;

        try {
            $this->startSession($sessions, $eventBus, $transactions, $walled, $currentSession)->start(
                SessionMother::DEFAULT_USER_ID,
                SessionMother::DEFAULT_ORG_ID,
                SessionMother::DEFAULT_DEVICE,
                null,
            );
            $this->fail('a login for an identity that no longer admits one must fail closed');
        } catch (SessionSubjectNotAdmissible $sessionSubjectNotAdmissible) {
            $this->assertSame($reason, $sessionSubjectNotAdmissible->reason());
        }

        $this->assertSame(1, $transactions->committed, 'the retirement committed');
        $this->assertSame(0, $transactions->rolledBack, 'nothing was rolled back');
        $this->assertSame(SessionStatus::REVOKED, $previous->status(), 'the carried session was retired');
        $this->assertSame(
            [SessionMother::DEFAULT_ID],
            \array_map(static fn (Session $session): ?string => $session->getId(), $sessions->saved),
            'the only write is the retirement: no session was minted',
        );
        $this->assertCount(1, $eventBus->publishedEvents);
        $this->assertInstanceOf(SessionRevoked::class, $eventBus->publishedEvents[0]);
        $this->assertSame(
            SessionMother::DEFAULT_ID,
            $currentSession->get()?->toString(),
            'no correlation was written for a session that does not exist',
        );
    }

    /**
     * @return iterable<string, array{SubjectAdmission}>
     */
    public static function provideARefusedMintRetiresTheCarriedSessionAndRaisesItsReasonCases(): iterable
    {
        yield 'suspended' => [SubjectAdmission::Suspended];
        yield 'deactivated' => [SubjectAdmission::Deactivated];
        yield 'gone' => [SubjectAdmission::Gone];
    }

    private function startSession(
        InMemorySessionRepository $sessions,
        RecordingEventBus $eventBus,
        TransactionSnapshottingManager $transactions,
        RecordingSessionSubjectLock $lock,
        RecordingCurrentSessionReference $currentSession,
    ): StartSession {
        return new StartSession(
            $sessions,
            $currentSession,
            $eventBus,
            $transactions,
            new FixedClock(SystemClock::now()),
            $lock,
        );
    }
}
