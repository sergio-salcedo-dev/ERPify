<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Session\Application;

use DateInterval;
use Erpify\Iam\Session\Application\StartSession;
use Erpify\Iam\Session\Domain\Entity\Session;
use Erpify\Iam\Session\Domain\Enum\SessionStatus;
use Erpify\Iam\Session\Domain\Event\SessionRevoked;
use Erpify\Iam\Session\Domain\Event\SessionStarted;
use Erpify\Iam\Session\Domain\SessionId;
use Erpify\Shared\Clock\Domain\SystemClock;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Tests\Double\Clock\FixedClock;
use Erpify\Tests\Unit\Iam\Session\Domain\Entity\Mother\SessionMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") the subject orchestrates a repository, the current-session
 * reference, the event bus, the transaction manager and the clock, and each case wires its own doubles
 */
#[CoversClass(StartSession::class)]
final class StartSessionTest extends TestCase
{
    private const string NOW = '2026-07-10T12:00:00+00:00';

    private const string REPLACED_ID = '0190c1d2-e3f4-7a5b-8c6d-1e2f3a4b5c70';

    private const string OTHER_USER_ID = '0190c1d2-e3f4-7a5b-8c6d-1e2f3a4b5c71';

    protected function setUp(): void
    {
        parent::setUp();
        SystemClock::set(FixedClock::at(self::NOW));
    }

    public function testMintsAnActiveSessionPublishesStartedAndWritesTheCorrelation(): void
    {
        $clock = FixedClock::at(self::NOW);
        $sessions = new InMemorySessionRepository();
        $eventBus = new RecordingEventBus();
        $currentSession = new RecordingCurrentSessionReference();
        $startSession = new StartSession(
            $sessions,
            $currentSession,
            $eventBus,
            new InlineTransactionManager(),
            $clock,
        );

        $sessionId = $startSession->start(
            SessionMother::DEFAULT_USER_ID,
            SessionMother::DEFAULT_ORG_ID,
            'Chrome on macOS',
            '203.0.113.7',
        );

        $this->assertCount(1, $sessions->saved);
        $session = $sessions->saved[0];
        $this->assertSame($sessionId->toString(), $session->getId());
        $this->assertSame(SessionStatus::ACTIVE, $session->status());
        $this->assertSame(
            $clock->now()->add(new DateInterval('P7D'))->format('c'),
            $session->expiresAt()->format('c'),
        );
        // The row's own stamp and its expiry come from one clock, so the lifetime is readable off the row.
        $this->assertSame(
            $session->getCreatedAt()->add(new DateInterval('P7D'))->format('c'),
            $session->expiresAt()->format('c'),
        );

        $this->assertCount(1, $eventBus->publishedEvents);
        $this->assertInstanceOf(SessionStarted::class, $eventBus->publishedEvents[0]);
    }

    public function testTheWrittenCorrelationIsTheMintedSessionId(): void
    {
        $currentSession = new RecordingCurrentSessionReference();
        $startSession = new StartSession(
            new InMemorySessionRepository(),
            $currentSession,
            new RecordingEventBus(),
            new InlineTransactionManager(),
            FixedClock::at(self::NOW),
        );

        $sessionId = $startSession->start(
            SessionMother::DEFAULT_USER_ID,
            SessionMother::DEFAULT_ORG_ID,
            'Chrome on macOS',
            null,
        );

        $reference = $currentSession->get();
        $this->assertInstanceOf(SessionId::class, $reference);
        $this->assertTrue($sessionId->equals($reference));
    }

    public function testALoginOverALiveCorrelatedSessionRevokesItBeforeMintingTheReplacement(): void
    {
        $replaced = $this->settled(SessionMother::active(id: self::REPLACED_ID));
        $sessions = new InMemorySessionRepository($replaced);
        $eventBus = new RecordingEventBus();
        $currentSession = new RecordingCurrentSessionReference(SessionId::fromString(self::REPLACED_ID));

        $sessionId = $this->startSession($sessions, $currentSession, $eventBus)->start(
            SessionMother::DEFAULT_USER_ID,
            SessionMother::DEFAULT_ORG_ID,
            'Chrome on macOS',
            null,
        );

        $this->assertSame(SessionStatus::REVOKED, $replaced->status());
        $this->assertNull($sessions->findActiveById(SessionId::fromString(self::REPLACED_ID)));
        $this->assertCount(1, $sessions->findByUserId(SessionMother::DEFAULT_USER_ID), 'no ghost device survives');

        $this->assertCount(2, $sessions->saved);
        $this->assertSame($replaced, $sessions->saved[0]);
        $this->assertSame($sessionId->toString(), $sessions->saved[1]->getId());

        // The single-revocation fact, carrying the replaced row as its aggregate, ahead of the new session's.
        $this->assertCount(2, $eventBus->publishedEvents);
        $this->assertInstanceOf(SessionRevoked::class, $eventBus->publishedEvents[0]);
        $this->assertSame(self::REPLACED_ID, $eventBus->publishedEvents[0]->aggregateId());
        $this->assertInstanceOf(SessionStarted::class, $eventBus->publishedEvents[1]);

        $reference = $currentSession->get();
        $this->assertInstanceOf(SessionId::class, $reference);
        $this->assertTrue($sessionId->equals($reference), 'the correlation moves to the minted session');
    }

    /**
     * Two people on one browser: the second sign-in replaces the first person's correlation, so the first
     * person's row is as unreachable as a same-identity one would be and is retired the same way.
     */
    public function testTheReplacedSessionIsRevokedEvenWhenItBelongsToAnotherIdentity(): void
    {
        $replaced = $this->settled(SessionMother::active(id: self::REPLACED_ID, userId: self::OTHER_USER_ID));
        $sessions = new InMemorySessionRepository($replaced);
        $eventBus = new RecordingEventBus();

        $this->startSession(
            $sessions,
            new RecordingCurrentSessionReference(SessionId::fromString(self::REPLACED_ID)),
            $eventBus,
        )->start(SessionMother::DEFAULT_USER_ID, SessionMother::DEFAULT_ORG_ID, 'Chrome on macOS', null);

        $this->assertSame(SessionStatus::REVOKED, $replaced->status());
        $this->assertSame([], $sessions->findByUserId(self::OTHER_USER_ID));
        $revoked = $eventBus->publishedEvents[0] ?? null;
        $this->assertInstanceOf(SessionRevoked::class, $revoked);
        $this->assertSame(self::REPLACED_ID, $revoked->aggregateId());
        $this->assertSame(self::OTHER_USER_ID, $revoked->userId());
    }

    public function testWithNoCorrelationNothingIsRevoked(): void
    {
        $bystander = $this->settled(SessionMother::active(id: self::REPLACED_ID));
        $sessions = new InMemorySessionRepository($bystander);
        $eventBus = new RecordingEventBus();

        $this->startSession($sessions, new RecordingCurrentSessionReference(), $eventBus)->start(
            SessionMother::DEFAULT_USER_ID,
            SessionMother::DEFAULT_ORG_ID,
            'Chrome on macOS',
            null,
        );

        $this->assertSame(SessionStatus::ACTIVE, $bystander->status(), 'another device of the identity is untouched');
        $this->assertCount(1, $sessions->saved);
        $this->assertCount(1, $eventBus->publishedEvents);
        $this->assertInstanceOf(SessionStarted::class, $eventBus->publishedEvents[0]);
    }

    public function testACorrelatedSessionAlreadyRevokedIsLeftAsItIs(): void
    {
        $replaced = $this->settled(SessionMother::active(id: self::REPLACED_ID));
        $replaced->revoke();
        $replaced->pullDomainEvents();

        $revokedAt = $replaced->revokedAt();

        $sessions = new InMemorySessionRepository($replaced);
        $eventBus = new RecordingEventBus();

        $this->startSession(
            $sessions,
            new RecordingCurrentSessionReference(SessionId::fromString(self::REPLACED_ID)),
            $eventBus,
        )->start(SessionMother::DEFAULT_USER_ID, SessionMother::DEFAULT_ORG_ID, 'Chrome on macOS', null);

        $this->assertSame($revokedAt, $replaced->revokedAt());
        $this->assertCount(1, $sessions->saved);
        $this->assertNotSame($replaced, $sessions->saved[0]);
        $this->assertCount(1, $eventBus->publishedEvents);
        $this->assertInstanceOf(SessionStarted::class, $eventBus->publishedEvents[0]);
    }

    public function testACorrelatedSessionPastItsExpiryIsLeftAsItIs(): void
    {
        // A day behind the clock the predicate reads, never a date on the calendar.
        $replaced = $this->settled(SessionMother::active(
            id: self::REPLACED_ID,
            expiresAt: SystemClock::now()->sub(new DateInterval('P1D')),
        ));
        $sessions = new InMemorySessionRepository($replaced);
        $eventBus = new RecordingEventBus();

        $this->startSession(
            $sessions,
            new RecordingCurrentSessionReference(SessionId::fromString(self::REPLACED_ID)),
            $eventBus,
        )->start(SessionMother::DEFAULT_USER_ID, SessionMother::DEFAULT_ORG_ID, 'Chrome on macOS', null);

        $this->assertSame(SessionStatus::ACTIVE, $replaced->status(), 'a lapsed session is never transitioned');
        $this->assertCount(1, $sessions->saved);
        $this->assertCount(1, $eventBus->publishedEvents);
        $this->assertInstanceOf(SessionStarted::class, $eventBus->publishedEvents[0]);
    }

    public function testACorrelationNamingNoRowRevokesNothing(): void
    {
        $sessions = new InMemorySessionRepository();
        $eventBus = new RecordingEventBus();

        $this->startSession(
            $sessions,
            new RecordingCurrentSessionReference(SessionId::fromString(self::REPLACED_ID)),
            $eventBus,
        )->start(SessionMother::DEFAULT_USER_ID, SessionMother::DEFAULT_ORG_ID, 'Chrome on macOS', null);

        $this->assertCount(1, $sessions->saved);
        $this->assertCount(1, $eventBus->publishedEvents);
        $this->assertInstanceOf(SessionStarted::class, $eventBus->publishedEvents[0]);
    }

    /**
     * The revocation belongs to the unit of work that mints the replacement: a transaction that never runs
     * its body leaves the replaced row live AND still correlated, rather than revoked with nothing minted.
     */
    public function testTheRevocationCommitsOnlyWithTheMint(): void
    {
        $replaced = $this->settled(SessionMother::active(id: self::REPLACED_ID));
        $sessions = new InMemorySessionRepository($replaced);
        $eventBus = new RecordingEventBus();
        $currentSession = new RecordingCurrentSessionReference(SessionId::fromString(self::REPLACED_ID));
        $refusing = $this->createStub(TransactionManager::class);
        $refusing
            ->method('transactional')
            ->willThrowException(new RuntimeException('The store refused the transaction.'))
        ;

        $startSession = new StartSession($sessions, $currentSession, $eventBus, $refusing, FixedClock::at(self::NOW));

        try {
            $startSession->start(SessionMother::DEFAULT_USER_ID, SessionMother::DEFAULT_ORG_ID, 'Chrome', null);
            $this->fail('A refused transaction must reach the minting caller, which fails the login closed.');
        } catch (RuntimeException $runtimeException) {
            $this->assertSame('The store refused the transaction.', $runtimeException->getMessage());
        }

        $this->assertSame(SessionStatus::ACTIVE, $replaced->status());
        $this->assertSame([], $sessions->saved);
        $this->assertSame([], $eventBus->publishedEvents);
        $reference = $currentSession->get();
        $this->assertInstanceOf(SessionId::class, $reference);
        $this->assertSame(self::REPLACED_ID, $reference->toString());
    }

    private function startSession(
        InMemorySessionRepository $sessions,
        RecordingCurrentSessionReference $currentSession,
        RecordingEventBus $eventBus,
    ): StartSession {
        return new StartSession(
            $sessions,
            $currentSession,
            $eventBus,
            new InlineTransactionManager(),
            FixedClock::at(self::NOW),
        );
    }

    /**
     * A preset row as the store would hand it back: its own `SessionStarted` was published when it was minted.
     */
    private function settled(Session $session): Session
    {
        $session->pullDomainEvents();

        return $session;
    }
}
