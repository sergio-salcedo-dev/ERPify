<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Session\Application;

use Erpify\Iam\Session\Application\RevokeAllSessions;
use Erpify\Iam\Session\Domain\Event\AllSessionsRevoked;
use Erpify\Shared\Clock\Domain\SystemClock;
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
    private const string NOW = '2026-07-10T12:00:00+00:00';

    private const string OTHER_ID = '0190c1d2-e3f4-7a5b-8c6d-1e2f3a4b5c7e';

    protected function setUp(): void
    {
        parent::setUp();
        SystemClock::set(FixedClock::at(self::NOW));
    }

    public function testBulkRevokesEverySessionOfTheUserAndPublishesTheBulkFact(): void
    {
        $sessions = new InMemorySessionRepository(
            SessionMother::active(),
            SessionMother::active(id: self::OTHER_ID),
        );
        $eventBus = new RecordingEventBus();
        $revokeAll = new RevokeAllSessions(
            $sessions,
            $eventBus,
            new InlineTransactionManager(),
            FixedClock::at(self::NOW),
            new RecordingSessionSubjectLock(),
        );

        $revokeAll->revoke(SessionMother::DEFAULT_USER_ID);

        $this->assertSame([SessionMother::DEFAULT_USER_ID], $sessions->revokeAllCalls);
        $this->assertSame([], $sessions->findByUserId(SessionMother::DEFAULT_USER_ID));
        $this->assertCount(1, $eventBus->publishedEvents);
        $this->assertInstanceOf(AllSessionsRevoked::class, $eventBus->publishedEvents[0]);
    }

    /**
     * The event states that sessions were revoked. An identity with none active — a suspension of somebody who
     * never signed in, a reset by somebody signed out everywhere — flips no row, so it records no fact, and the
     * call still completes as the success it is.
     */
    public function testRevokingAnIdentityWithNoActiveSessionPublishesNothing(): void
    {
        $revoked = SessionMother::active(id: self::OTHER_ID);
        $revoked->revoke();

        $sessions = new InMemorySessionRepository($revoked);
        $eventBus = new RecordingEventBus();

        (new RevokeAllSessions(
            $sessions,
            $eventBus,
            new InlineTransactionManager(),
            FixedClock::at(self::NOW),
            new RecordingSessionSubjectLock(),
        ))->revoke(SessionMother::DEFAULT_USER_ID);

        $this->assertSame([SessionMother::DEFAULT_USER_ID], $sessions->revokeAllCalls, 'the revocation still ran');
        $this->assertSame([], $eventBus->publishedEvents);
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
            FixedClock::at(self::NOW),
            new RecordingSessionSubjectLock(),
        ))->revoke(SessionMother::DEFAULT_USER_ID);

        $this->assertSame(['lock', 'revoke'], $steps);
    }

    /**
     * Every caller revokes after its own commit, so the person's erasure can commit in between. Arriving second,
     * the bulk revocation finds no identity: appending `AllSessionsRevoked` then would put the erased person's
     * id into the business log after the erasure had rewritten it.
     */
    public function testRevokesNothingAndPublishesNothingOnceTheIdentityIsErased(): void
    {
        $sessions = new InMemorySessionRepository();
        $eventBus = new RecordingEventBus();
        $subjectLock = new RecordingSessionSubjectLock();
        $subjectLock->live = false;

        (new RevokeAllSessions(
            $sessions,
            $eventBus,
            new InlineTransactionManager(),
            FixedClock::at(self::NOW),
            $subjectLock,
        ))->revoke(SessionMother::DEFAULT_USER_ID);

        $this->assertSame([SessionMother::DEFAULT_USER_ID], \array_column($subjectLock->calls, 0));
        $this->assertSame(
            ['holdsLive'],
            $subjectLock->methodsCalled(),
            'a revocation acts on identities that stopped being active, so it holds existence and not admission',
        );
        $this->assertSame([], $sessions->revokeAllCalls);
        $this->assertSame([], $eventBus->publishedEvents);
    }

    /** The erasure's order — identity, then sessions — so the two cannot deadlock. */
    public function testHoldsTheIdentityBeforeLockingAnySession(): void
    {
        $sessions = new InMemorySessionRepository();
        $subjectLock = new RecordingSessionSubjectLock();
        $locksHeldWhenSessionsLocked = [];
        $sessions->beforeLockActive = static function () use ($subjectLock, &$locksHeldWhenSessionsLocked): void {
            $locksHeldWhenSessionsLocked[] = \count($subjectLock->calls);
        };

        (new RevokeAllSessions(
            $sessions,
            new RecordingEventBus(),
            new InlineTransactionManager(),
            FixedClock::at(self::NOW),
            $subjectLock,
        ))->revoke(SessionMother::DEFAULT_USER_ID);

        $this->assertSame([1], $locksHeldWhenSessionsLocked);
    }

    public function testRejectsAMalformedUserIdBeforeTouchingTheStore(): void
    {
        $sessions = new InMemorySessionRepository();
        $revokeAll = new RevokeAllSessions(
            $sessions,
            new RecordingEventBus(),
            new InlineTransactionManager(),
            FixedClock::at(self::NOW),
            new RecordingSessionSubjectLock(),
        );

        $this->expectException(InvalidUuidException::class);

        try {
            $revokeAll->revoke('not-a-uuid');
        } finally {
            $this->assertSame([], $sessions->revokeAllCalls);
        }
    }
}
