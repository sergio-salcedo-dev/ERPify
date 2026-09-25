<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Session\Application;

use DateTimeImmutable;
use Erpify\Iam\Session\Application\RevokeOtherSessions;
use Erpify\Iam\Session\Domain\Entity\Session;
use Erpify\Iam\Session\Domain\Event\OtherSessionsRevoked;
use Erpify\Iam\Session\Domain\Exception\SessionNoLongerActive;
use Erpify\Iam\Session\Domain\SessionId;
use Erpify\Shared\Uuid\Domain\InvalidUuidException;
use Erpify\Tests\Double\Clock\FixedClock;
use Erpify\Tests\Unit\Iam\Session\Domain\Entity\Mother\SessionMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(RevokeOtherSessions::class)]
final class RevokeOtherSessionsTest extends TestCase
{
    private const string OTHER_ID = '0190c1d2-e3f4-7a5b-8c6d-1e2f3a4b5c7e';

    public function testBulkRevokesEveryOtherSessionExceptTheCurrentAndPublishesTheBulkFact(): void
    {
        $sessions = new InMemorySessionRepository(
            SessionMother::active(),
            SessionMother::active(id: self::OTHER_ID),
        );
        $eventBus = new RecordingEventBus();
        $current = SessionId::fromString(SessionMother::DEFAULT_ID);

        $this->useCase($sessions, $eventBus)->revoke(SessionMother::DEFAULT_USER_ID, $current);

        $this->assertSame([SessionMother::DEFAULT_USER_ID], $sessions->revokeOthersCalls);
        $this->assertInstanceOf(SessionId::class, $sessions->lastRevokeOthersexcept);
        $this->assertTrue($current->equals($sessions->lastRevokeOthersexcept));
        $this->assertSame(
            [SessionMother::DEFAULT_ID],
            \array_map(
                static fn (Session $session): string => $session->getId() ?? '',
                $sessions->findByUserId(SessionMother::DEFAULT_USER_ID),
            ),
        );
        $this->assertCount(1, $eventBus->publishedEvents);
        $revokedFact = $eventBus->publishedEvents[0];
        $this->assertInstanceOf(OtherSessionsRevoked::class, $revokedFact);
        $this->assertSame(SessionMother::DEFAULT_ID, $revokedFact->keptSessionId());
    }

    /**
     * The request was admitted while its session was alive, and another session of the same identity evicted
     * it before this one's decision took the lock. Acting on the admission would let the evicted session revoke
     * the one that evicted it — the loop a stolen session runs against an owner's recovered one. Under the lock
     * the caller finds its own row gone, and it revokes nothing.
     */
    public function testASessionEvictedWhileItsRequestWaitedIsRefusedAndRevokesNothing(): void
    {
        $sessions = new InMemorySessionRepository(
            SessionMother::active(),
            SessionMother::active(id: self::OTHER_ID),
        );
        $sessions->beforeLockActive = static function () use ($sessions): void {
            $sessions->revokeOthersForUser(SessionMother::DEFAULT_USER_ID, SessionId::fromString(self::OTHER_ID));
        };
        $eventBus = new RecordingEventBus();

        try {
            $this->useCase($sessions, $eventBus)->revoke(
                SessionMother::DEFAULT_USER_ID,
                SessionId::fromString(SessionMother::DEFAULT_ID),
            );
            $this->fail('Expected an evicted session to be refused.');
        } catch (SessionNoLongerActive) {
            // 401 session-expired, the gate's own answer to a revoked session
        }

        $this->assertSame(
            [self::OTHER_ID],
            \array_map(
                static fn (Session $session): string => $session->getId() ?? '',
                $sessions->findByUserId(SessionMother::DEFAULT_USER_ID),
            ),
            'the evicted session revoked the session that evicted it',
        );
        $this->assertSame([], $eventBus->publishedEvents);
    }

    public function testRejectsAMalformedUserIdBeforeTouchingTheStore(): void
    {
        $sessions = new InMemorySessionRepository();

        try {
            $this->useCase($sessions, new RecordingEventBus())->revoke('not-a-uuid', SessionId::generate());
            $this->fail('Expected a malformed user id to be rejected at ingress.');
        } catch (InvalidUuidException) {
            // guarded before any store write
        }

        $this->assertSame([], $sessions->revokeOthersCalls);
    }

    private function useCase(InMemorySessionRepository $sessions, RecordingEventBus $eventBus): RevokeOtherSessions
    {
        return new RevokeOtherSessions(
            $sessions,
            $eventBus,
            new InlineTransactionManager(),
            new FixedClock(new DateTimeImmutable('2026-07-10T12:00:00+00:00')),
        );
    }
}
