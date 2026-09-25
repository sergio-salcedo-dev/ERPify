<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Session\Application;

use DateInterval;
use Erpify\Iam\Session\Application\StartSession;
use Erpify\Iam\Session\Domain\Enum\SessionStatus;
use Erpify\Iam\Session\Domain\Event\SessionStarted;
use Erpify\Iam\Session\Domain\SessionId;
use Erpify\Shared\Clock\Domain\SystemClock;
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
    private const string NOW = '2026-07-10T12:00:00+00:00';

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
}
