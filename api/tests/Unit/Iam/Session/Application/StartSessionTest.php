<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Session\Application;

use DateInterval;
use DateTimeImmutable;
use Erpify\Iam\Session\Application\StartSession;
use Erpify\Iam\Session\Domain\Enum\SessionStatus;
use Erpify\Iam\Session\Domain\Event\SessionStarted;
use Erpify\Iam\Session\Domain\SessionId;
use Erpify\Tests\Double\Clock\FixedClock;
use Erpify\Tests\Unit\Iam\Session\Domain\Entity\Mother\SessionMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(StartSession::class)]
final class StartSessionTest extends TestCase
{
    public function testMintsAnActiveSessionPublishesStartedAndWritesTheCorrelation(): void
    {
        $now = new DateTimeImmutable('2026-07-10T12:00:00+00:00');
        $sessions = new InMemorySessionRepository();
        $eventBus = new RecordingEventBus();
        $currentSession = new RecordingCurrentSessionReference();
        $startSession = new StartSession(
            $sessions,
            $currentSession,
            $eventBus,
            new InlineTransactionManager(),
            new FixedClock($now),
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
            $now->add(new DateInterval('P7D'))->format('c'),
            $session->expiresAt()->format('c'),
        );

        $this->assertCount(1, $eventBus->publishedEvents);
        $this->assertInstanceOf(SessionStarted::class, $eventBus->publishedEvents[0]);
    }

    /**
     * Every instant the session carries is the one the test supplied: its stamps, the event it records and the
     * expiry window. An aggregate stamped from any other source would expire relative to one instant and have
     * been created at another — which is how a session once came to expire 24 years before its own creation in
     * a green test.
     */
    public function testEveryInstantTheSessionCarriesDerivesFromTheClockTheUseCaseWasGiven(): void
    {
        $now = new DateTimeImmutable('2026-07-10T12:00:00+00:00');
        $sessions = new InMemorySessionRepository();
        $eventBus = new RecordingEventBus();

        (new StartSession(
            $sessions,
            new RecordingCurrentSessionReference(),
            $eventBus,
            new InlineTransactionManager(),
            new FixedClock($now),
        ))->start(SessionMother::DEFAULT_USER_ID, SessionMother::DEFAULT_ORG_ID, 'Chrome on macOS', null);

        $this->assertCount(1, $sessions->saved);
        $this->assertCount(1, $eventBus->publishedEvents);
        $session = $sessions->saved[0];
        $started = $eventBus->publishedEvents[0];
        $this->assertInstanceOf(SessionStarted::class, $started);
        $this->assertSame('2026-07-10T12:00:00+00:00', $session->getCreatedAt()->format('c'));
        $this->assertSame('2026-07-10T12:00:00+00:00', $session->getUpdatedAt()->format('c'));
        $this->assertSame('2026-07-10T12:00:00+00:00', $started->occurredOn()->format('c'));
        $this->assertSame('2026-07-17T12:00:00+00:00', $session->expiresAt()->format('c'));
    }

    public function testTheWrittenCorrelationIsTheMintedSessionId(): void
    {
        $currentSession = new RecordingCurrentSessionReference();
        $startSession = new StartSession(
            new InMemorySessionRepository(),
            $currentSession,
            new RecordingEventBus(),
            new InlineTransactionManager(),
            new FixedClock(new DateTimeImmutable('2026-07-10T12:00:00+00:00')),
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
}
