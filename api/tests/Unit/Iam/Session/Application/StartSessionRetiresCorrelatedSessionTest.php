<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Session\Application;

use Erpify\Iam\Session\Application\StartSession;
use Erpify\Iam\Session\Domain\Enum\SessionStatus;
use Erpify\Iam\Session\Domain\SessionId;
use Erpify\Shared\Clock\Domain\SystemClock;
use Erpify\Tests\Double\Clock\FixedClock;
use Erpify\Tests\Unit\Iam\Session\Domain\Entity\Mother\SessionMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A login over a native session that already correlates a registry row — a re-login from the same browser, since
 * the firewall migrates the session and keeps its attributes. The row that cookie pointed at is retired in the
 * transaction that mints its replacement; nothing else is.
 *
 * Events are asserted by name so the class stays within the coupling budget its sibling already spends.
 *
 * @internal
 */
#[CoversClass(StartSession::class)]
final class StartSessionRetiresCorrelatedSessionTest extends TestCase
{
    private const string REVOKED = 'erpify.iam.session.revoked';

    private const string STARTED = 'erpify.iam.session.started';

    private RecordingEventBus $eventBus;

    protected function setUp(): void
    {
        parent::setUp();
        $this->eventBus = new RecordingEventBus();
    }

    /**
     * Revoked before the new row is saved, its `SessionRevoked` published ahead of the new `SessionStarted`, and
     * every write and event inside the one unit of work — so both land or neither does.
     */
    public function testAReloginRevokesTheCorrelatedSessionInTheSameTransaction(): void
    {
        $previous = SessionMother::active();
        $previous->pullDomainEvents();

        $sessions = new InMemorySessionRepository($previous);
        $transactions = new TransactionSnapshottingManager($sessions, $this->eventBus);
        $currentSession = $this->correlatedTo(SessionMother::DEFAULT_ID);

        $sessionId = $this->start($currentSession, $sessions, $transactions);

        $this->assertSame(SessionStatus::REVOKED, $previous->status());
        $this->assertSame([SessionMother::DEFAULT_ID, $sessionId->toString()], $this->savedIds($sessions));
        $this->assertSame(
            [
                [self::REVOKED, SessionMother::DEFAULT_ID],
                [self::STARTED, $sessionId->toString()],
            ],
            $this->published(),
        );

        $this->assertSame(1, $transactions->calls, 'revocation and mint are one unit of work');
        $this->assertSame(2, $transactions->savedWithin, 'both writes happened inside it');
        $this->assertSame(2, $transactions->publishedWithin, 'and so did both events');

        $reference = $currentSession->get();
        $this->assertInstanceOf(SessionId::class, $reference);
        $this->assertTrue($sessionId->equals($reference), 'the correlation now names the new session');
    }

    /**
     * The row was reachable only through this cookie, whose correlation is about to be overwritten — so it is
     * revoked whoever owns it, and no other session of either identity is touched.
     */
    public function testTheCorrelatedSessionIsRevokedWhoeverOwnsItAndNoOtherSessionIsTouched(): void
    {
        $previous = SessionMother::active(userId: '0190c1d2-e3f4-7a5b-8c6d-1e2f3a4b5c70');
        $sibling = SessionMother::active(id: '0190c1d2-e3f4-7a5b-8c6d-1e2f3a4b5c71');

        $sessions = new InMemorySessionRepository($previous, $sibling);

        $this->start($this->correlatedTo(SessionMother::DEFAULT_ID), $sessions);

        $this->assertSame(SessionStatus::REVOKED, $previous->status());
        $this->assertSame(SessionStatus::ACTIVE, $sibling->status(), 'this is not a log-out-everywhere');
        $this->assertSame([], $sessions->revokeAllCalls);
        $this->assertSame([], $sessions->revokeOthersCalls);
    }

    #[DataProvider('provideACorrelationToAnInadmissibleSessionOnlyMintsCases')]
    public function testACorrelationToAnInadmissibleSessionOnlyMints(string $shape): void
    {
        $preset = SessionMother::active(
            expiresAt: 'lapsed' === $shape ? SystemClock::now()->modify('-1 day') : null,
        );

        if ('revoked' === $shape) {
            $preset->revoke();
        }

        $preset->pullDomainEvents();
        $sessions = 'unknown' === $shape ? new InMemorySessionRepository() : new InMemorySessionRepository($preset);

        $sessionId = $this->start($this->correlatedTo(SessionMother::DEFAULT_ID), $sessions);

        $this->assertSame([SessionMother::DEFAULT_ID], $sessions->lockActiveByIdCalls);
        $this->assertSame([$sessionId->toString()], $this->savedIds($sessions));
        $this->assertSame([[self::STARTED, $sessionId->toString()]], $this->published());
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function provideACorrelationToAnInadmissibleSessionOnlyMintsCases(): iterable
    {
        yield 'no such row' => ['unknown'];
        yield 'already revoked' => ['revoked'];
        yield 'lapsed' => ['lapsed'];
    }

    /**
     * The row is revoked (in bulk, which records no per-row event) or deleted by a rival that commits while this
     * login waits on the row's lock. The locked read then finds it inadmissible, so the login publishes no
     * `SessionRevoked` for it and never writes it: the rival's `revokedAt` stands, and a deleted row is not
     * resurrected into an event naming its owner.
     */
    #[DataProvider('provideARowARivalRetiresUnderTheLockIsNeitherRevokedAgainNorAnnouncedCases')]
    public function testARowARivalRetiresUnderTheLockIsNeitherRevokedAgainNorAnnounced(string $rival): void
    {
        $previous = SessionMother::active();
        $previous->pullDomainEvents();

        $sessions = new InMemorySessionRepository($previous);
        $sessions->beforeLockActiveById = static function () use ($sessions, $rival): void {
            'delete' === $rival
                ? $sessions->deleteAllForUser(SessionMother::DEFAULT_USER_ID)
                : $sessions->revokeAllForUser(SessionMother::DEFAULT_USER_ID);
        };

        $sessionId = $this->start($this->correlatedTo(SessionMother::DEFAULT_ID), $sessions);

        $this->assertSame([SessionMother::DEFAULT_ID], $sessions->lockActiveByIdCalls, 'the read took the lock');
        $this->assertSame([], $sessions->findActiveByIdCalls, 'and no unlocked read decided anything');
        $this->assertSame([$sessionId->toString()], $this->savedIds($sessions), 'the earlier row is not written');
        $this->assertSame([[self::STARTED, $sessionId->toString()]], $this->published());
        $this->assertSame([], $previous->pullDomainEvents(), 'nor is a revocation left pending on it');
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function provideARowARivalRetiresUnderTheLockIsNeitherRevokedAgainNorAnnouncedCases(): iterable
    {
        yield 'revoked by a log-out-everywhere' => ['revoke'];
        yield 'deleted by an erasure' => ['delete'];
    }

    public function testAFirstLoginLooksNothingUp(): void
    {
        $sessions = new InMemorySessionRepository();

        $sessionId = $this->start(new RecordingCurrentSessionReference(), $sessions);

        $this->assertSame([], $sessions->lockActiveByIdCalls, 'no correlation means no extra lookup');
        $this->assertSame([$sessionId->toString()], $this->savedIds($sessions));
        $this->assertSame([[self::STARTED, $sessionId->toString()]], $this->published());
    }

    private function correlatedTo(string $sessionId): RecordingCurrentSessionReference
    {
        return new RecordingCurrentSessionReference(SessionId::fromString($sessionId));
    }

    private function start(
        RecordingCurrentSessionReference $currentSession,
        InMemorySessionRepository $sessions,
        ?TransactionSnapshottingManager $transactions = null,
    ): SessionId {
        // Seeded from the ambient clock, so the injected one and the one the aggregate reads agree.
        $startSession = new StartSession(
            $sessions,
            $currentSession,
            $this->eventBus,
            $transactions ?? new TransactionSnapshottingManager($sessions, $this->eventBus),
            new FixedClock(SystemClock::now()),
        );

        return $startSession->start(
            SessionMother::DEFAULT_USER_ID,
            SessionMother::DEFAULT_ORG_ID,
            SessionMother::DEFAULT_DEVICE,
            null,
        );
    }

    /**
     * @return list<?string>
     */
    private function savedIds(InMemorySessionRepository $sessions): array
    {
        $ids = [];

        foreach ($sessions->saved as $session) {
            $ids[] = $session->getId();
        }

        return $ids;
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function published(): array
    {
        $events = [];

        foreach ($this->eventBus->publishedEvents as $event) {
            $events[] = [$event::eventName(), $event->aggregateId()];
        }

        return $events;
    }
}
