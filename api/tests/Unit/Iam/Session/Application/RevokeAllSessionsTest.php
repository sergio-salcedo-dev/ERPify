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
     * The UPDATE's own scan meets the user's rows in heap order, which is not id order; taking the ordered lock
     * first is what keeps this teardown from closing a cycle with a concurrent "sign out my other devices".
     */
    public function testLocksTheActiveSetInIdOrderBeforeTheBulkStatement(): void
    {
        $sessions = new InMemorySessionRepository();
        $steps = [];
        $sessions->beforeLockActive = static function () use (&$steps): void {
            $steps[] = 'lock';
        };
        $sessions->onRevokeAll = static function () use (&$steps): void {
            $steps[] = 'revoke';
        };

        (new RevokeAllSessions(
            $sessions,
            new RecordingEventBus(),
            new InlineTransactionManager(),
            new FixedClock(new DateTimeImmutable('2026-07-10T12:00:00+00:00')),
        ))->revoke(SessionMother::DEFAULT_USER_ID);

        $this->assertSame(['lock', 'revoke'], $steps);
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
