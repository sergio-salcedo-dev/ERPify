<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Session\Application;

use DateTimeImmutable;
use Erpify\Iam\Session\Application\RevokeAllSessions;
use Erpify\Iam\Session\Domain\Event\AllSessionsRevoked;
use Erpify\Shared\Uuid\Domain\InvalidUuidException;
use Erpify\Tests\Double\Clock\FixedClock;
use Erpify\Tests\Unit\Iam\Session\Domain\Entity\Mother\SessionMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(RevokeAllSessions::class)]
final class RevokeAllSessionsTest extends TestCase
{
    public function testBulkRevokesEverySessionOfTheUserAndPublishesTheBulkFact(): void
    {
        $sessions = new InMemorySessionRepository();
        $eventBus = new RecordingEventBus();
        $revokeAll = new RevokeAllSessions(
            $sessions,
            $eventBus,
            new InlineTransactionManager(),
            new FixedClock(new DateTimeImmutable('2026-07-10T12:00:00+00:00')),
        );

        $revokeAll->revoke(SessionMother::DEFAULT_USER_ID);

        $this->assertSame([SessionMother::DEFAULT_USER_ID], $sessions->revokeAllCalls);
        $this->assertCount(1, $eventBus->publishedEvents);
        $this->assertInstanceOf(AllSessionsRevoked::class, $eventBus->publishedEvents[0]);
    }

    /**
     * The rows and the fact are one reading: the store stamps the instant the use case read, never one of its
     * own, so `revokedAt` and the event's `occurredOn` cannot disagree.
     */
    public function testTheRevokedRowsAndThePublishedFactCarryTheSameInstant(): void
    {
        $now = new DateTimeImmutable('2026-07-10T12:00:00+00:00');
        $session = SessionMother::active(expiresAt: $now->modify('+1 hour'), startedAt: $now->modify('-1 hour'));
        $eventBus = new RecordingEventBus();

        (new RevokeAllSessions(
            new InMemorySessionRepository($session),
            $eventBus,
            new InlineTransactionManager(),
            new FixedClock($now),
        ))->revoke(SessionMother::DEFAULT_USER_ID);

        $this->assertCount(1, $eventBus->publishedEvents);
        $this->assertSame('2026-07-10T12:00:00+00:00', $session->revokedAt()?->format('c'));
        $this->assertSame('2026-07-10T12:00:00+00:00', $eventBus->publishedEvents[0]->occurredOn()->format('c'));
    }

    public function testRejectsAMalformedUserIdBeforeTouchingTheStore(): void
    {
        $sessions = new InMemorySessionRepository();
        $revokeAll = new RevokeAllSessions(
            $sessions,
            new RecordingEventBus(),
            new InlineTransactionManager(),
            new FixedClock(new DateTimeImmutable('2026-07-10T12:00:00+00:00')),
        );

        $this->expectException(InvalidUuidException::class);

        try {
            $revokeAll->revoke('not-a-uuid');
        } finally {
            $this->assertSame([], $sessions->revokeAllCalls);
        }
    }
}
