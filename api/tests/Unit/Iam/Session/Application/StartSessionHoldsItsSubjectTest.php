<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Session\Application;

use Erpify\Iam\Session\Application\StartSession;
use Erpify\Iam\Session\Domain\Enum\SessionStatus;
use Erpify\Iam\Session\Domain\Exception\SessionStoreUnavailable;
use Erpify\Iam\Session\Domain\SessionId;
use Erpify\Shared\Clock\Domain\SystemClock;
use Erpify\Tests\Double\Clock\FixedClock;
use Erpify\Tests\Unit\Iam\Session\Domain\Entity\Mother\SessionMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A login holds the identity it mints a session for, inside the unit of work and ahead of every session row, so it
 * lands on one side of that identity's erasure or the other. The real lock and the erasure it waits on are proved
 * against Postgres in `LoginRacingAnErasureFunctionalTest`; this pins the use case's half — where the lock is taken
 * and what a vanished identity leaves behind.
 *
 * @internal
 */
#[CoversClass(StartSession::class)]
final class StartSessionHoldsItsSubjectTest extends TestCase
{
    public function testTheIdentityIsHeldInsideTheUnitOfWorkBeforeAnySessionIsWritten(): void
    {
        $previous = SessionMother::active();
        $previous->pullDomainEvents();

        $sessions = new InMemorySessionRepository($previous);
        $eventBus = new RecordingEventBus();
        $transactions = new TransactionSnapshottingManager($sessions, $eventBus);
        $lock = new RecordingSessionSubjectLock($sessions, $transactions);

        $this->startSession($sessions, $eventBus, $transactions, $lock, SessionMother::DEFAULT_ID)->start(
            SessionMother::DEFAULT_USER_ID,
            SessionMother::DEFAULT_ORG_ID,
            SessionMother::DEFAULT_DEVICE,
            null,
        );

        $this->assertSame([[SessionMother::DEFAULT_USER_ID, 0, true]], $lock->calls);
        $this->assertSame(SessionStatus::REVOKED, $previous->status(), 'and the login then went ahead');
    }

    /**
     * Arriving second, behind an erasure that has committed: no session is minted, the one the cookie correlated is
     * not revoked, nothing is announced and the correlation is left as it was.
     */
    public function testALoginWhoseIdentityIsGoneMintsRevokesAndAnnouncesNothing(): void
    {
        $previous = SessionMother::active();
        $previous->pullDomainEvents();

        $sessions = new InMemorySessionRepository($previous);
        $eventBus = new RecordingEventBus();
        $transactions = new TransactionSnapshottingManager($sessions, $eventBus);
        $currentSession = new RecordingCurrentSessionReference(SessionId::fromString(SessionMother::DEFAULT_ID));
        $gone = new RecordingSessionSubjectLock();
        $gone->live = false;

        $startSession = new StartSession(
            $sessions,
            $currentSession,
            $eventBus,
            $transactions,
            new FixedClock(SystemClock::now()),
            $gone,
        );

        try {
            $startSession->start(
                SessionMother::DEFAULT_USER_ID,
                SessionMother::DEFAULT_ORG_ID,
                SessionMother::DEFAULT_DEVICE,
                null,
            );
            $this->fail('a login for an identity that no longer exists must fail closed');
        } catch (SessionStoreUnavailable) {
            // The failure the minting listener turns into a dropped cookie and a 503.
        }

        $this->assertSame([], $sessions->saved, 'nothing was minted or revoked');
        $this->assertSame([], $sessions->lockActiveByIdCalls, 'the correlated row was not even read');
        $this->assertSame(SessionStatus::ACTIVE, $previous->status());
        $this->assertSame([], $eventBus->publishedEvents);
        $this->assertSame(SessionMother::DEFAULT_ID, $currentSession->get()?->toString());
    }

    private function startSession(
        InMemorySessionRepository $sessions,
        RecordingEventBus $eventBus,
        TransactionSnapshottingManager $transactions,
        RecordingSessionSubjectLock $lock,
        string $correlatedId,
    ): StartSession {
        return new StartSession(
            $sessions,
            new RecordingCurrentSessionReference(SessionId::fromString($correlatedId)),
            $eventBus,
            $transactions,
            new FixedClock(SystemClock::now()),
            $lock,
        );
    }
}
