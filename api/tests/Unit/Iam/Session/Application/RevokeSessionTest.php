<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Session\Application;

use DateInterval;
use Erpify\Iam\Session\Application\RevokeSession;
use Erpify\Iam\Session\Domain\Enum\SessionStatus;
use Erpify\Iam\Session\Domain\Event\SessionRevoked;
use Erpify\Iam\Session\Domain\SessionId;
use Erpify\Shared\Clock\Domain\SystemClock;
use Erpify\Tests\Unit\Iam\Session\Domain\Entity\Mother\SessionMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(RevokeSession::class)]
final class RevokeSessionTest extends TestCase
{
    public function testRevokesAnActiveSessionAndPublishesSessionRevoked(): void
    {
        $session = SessionMother::active();
        $session->pullDomainEvents();

        $sessions = new InMemorySessionRepository($session);
        $eventBus = new RecordingEventBus();
        $revokeSession = new RevokeSession($sessions, $eventBus, new InlineTransactionManager());

        $revokeSession->revoke(SessionId::fromString(SessionMother::DEFAULT_ID));

        $this->assertSame([$session], $sessions->saved);
        $this->assertSame(SessionStatus::REVOKED, $session->status());
        $this->assertCount(1, $eventBus->publishedEvents);
        $this->assertInstanceOf(SessionRevoked::class, $eventBus->publishedEvents[0]);
    }

    public function testRevokingAnAlreadyInertSessionIsANoOp(): void
    {
        $sessions = new InMemorySessionRepository();
        $eventBus = new RecordingEventBus();
        $revokeSession = new RevokeSession($sessions, $eventBus, new InlineTransactionManager());

        $revokeSession->revoke(SessionId::generate());

        $this->assertSame([], $sessions->saved);
        $this->assertSame([], $eventBus->publishedEvents);
    }

    public function testRevokingATimeExpiredSessionIsANoOp(): void
    {
        // "Expired" is a day behind the clock the predicate reads, not a date on the calendar: an
        // absolute literal makes this case depend on the suite's instant sitting after it.
        $session = SessionMother::active(expiresAt: SystemClock::now()->sub(new DateInterval('P1D')));
        $session->pullDomainEvents();

        $sessions = new InMemorySessionRepository($session);
        $eventBus = new RecordingEventBus();
        $revokeSession = new RevokeSession($sessions, $eventBus, new InlineTransactionManager());

        $revokeSession->revoke(SessionId::fromString(SessionMother::DEFAULT_ID));

        $this->assertSame([], $sessions->saved);
        $this->assertSame([], $eventBus->publishedEvents);
        $this->assertSame(SessionStatus::ACTIVE, $session->status(), 'a lapsed session is never transitioned');
    }

    /**
     * The row reads `ACTIVE` before the transaction, and a rival — a bulk revocation, which records no per-row
     * event, or an erasure — retires it and commits while this call waits on the row's lock. The decision is the
     * locked read's, so nothing is written and no `SessionRevoked` is published for a revocation that already
     * happened or for a row that no longer exists.
     */
    #[DataProvider('provideARowARivalRetiresUnderTheLockIsNeitherRevokedAgainNorAnnouncedCases')]
    public function testARowARivalRetiresUnderTheLockIsNeitherRevokedAgainNorAnnounced(string $rival): void
    {
        $session = SessionMother::active();
        $session->pullDomainEvents();

        $sessions = new InMemorySessionRepository($session);
        $sessions->beforeLockActiveById = static function () use ($sessions, $rival): void {
            'delete' === $rival
                ? $sessions->deleteAllForUser(SessionMother::DEFAULT_USER_ID)
                : $sessions->revokeAllForUser(SessionMother::DEFAULT_USER_ID);
        };
        $eventBus = new RecordingEventBus();

        (new RevokeSession($sessions, $eventBus, new InlineTransactionManager()))
            ->revoke(SessionId::fromString(SessionMother::DEFAULT_ID))
        ;

        $this->assertSame([SessionMother::DEFAULT_ID], $sessions->findActiveByIdCalls, 'it read ACTIVE first');
        $this->assertSame([SessionMother::DEFAULT_ID], $sessions->lockActiveByIdCalls, 'and decided under the lock');
        $this->assertSame([], $sessions->saved);
        $this->assertSame([], $eventBus->publishedEvents);
        $this->assertSame([], $session->pullDomainEvents(), 'nor is a revocation left pending on it');
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function provideARowARivalRetiresUnderTheLockIsNeitherRevokedAgainNorAnnouncedCases(): iterable
    {
        yield 'revoked by a log-out-everywhere' => ['revoke'];
        yield 'deleted by an erasure' => ['delete'];
    }

    /**
     * Inert on the unlocked read means no transaction is opened at all: revocation is terminal and expiry
     * absolute, so no rival can make the row admissible again.
     */
    public function testAnInertSessionOpensNoTransaction(): void
    {
        $sessions = new InMemorySessionRepository();
        $transactions = new TransactionSnapshottingManager($sessions, new RecordingEventBus());

        (new RevokeSession($sessions, new RecordingEventBus(), $transactions))->revoke(SessionId::generate());

        $this->assertSame(0, $transactions->calls);
        $this->assertSame([], $sessions->lockActiveByIdCalls);
    }
}
