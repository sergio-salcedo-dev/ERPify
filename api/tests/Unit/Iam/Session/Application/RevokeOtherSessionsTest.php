<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Session\Application;

use Erpify\Iam\Session\Application\RevokeOtherSessions;
use Erpify\Iam\Session\Domain\Event\OtherSessionsRevoked;
use Erpify\Iam\Session\Domain\SessionId;
use Erpify\Shared\Clock\Domain\SystemClock;
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
    private const string NOW = '2026-07-10T12:00:00+00:00';

    protected function setUp(): void
    {
        parent::setUp();
        SystemClock::set(FixedClock::at(self::NOW));
    }

    public function testBulkRevokesEveryOtherSessionExceptTheCurrentAndPublishesTheBulkFact(): void
    {
        $sessions = new InMemorySessionRepository();
        $eventBus = new RecordingEventBus();
        $current = SessionId::fromString(SessionMother::DEFAULT_ID);
        $revokeOthers = new RevokeOtherSessions(
            $sessions,
            $eventBus,
            new InlineTransactionManager(),
            FixedClock::at(self::NOW),
        );

        $revokeOthers->revoke(SessionMother::DEFAULT_USER_ID, $current);

        $this->assertSame([SessionMother::DEFAULT_USER_ID], $sessions->revokeOthersCalls);
        $this->assertInstanceOf(SessionId::class, $sessions->lastRevokeOthersexcept);
        $this->assertTrue($current->equals($sessions->lastRevokeOthersexcept));
        $this->assertCount(1, $eventBus->publishedEvents);
        $revokedFact = $eventBus->publishedEvents[0];
        $this->assertInstanceOf(OtherSessionsRevoked::class, $revokedFact);
        $this->assertSame(SessionMother::DEFAULT_ID, $revokedFact->keptSessionId());
    }

    public function testRejectsAMalformedUserIdBeforeTouchingTheStore(): void
    {
        $sessions = new InMemorySessionRepository();
        $revokeOthers = new RevokeOtherSessions(
            $sessions,
            new RecordingEventBus(),
            new InlineTransactionManager(),
            FixedClock::at(self::NOW),
        );

        try {
            $revokeOthers->revoke('not-a-uuid', SessionId::generate());
            $this->fail('Expected a malformed user id to be rejected at ingress.');
        } catch (InvalidUuidException) {
            // guarded before any store write
        }

        $this->assertSame([], $sessions->revokeOthersCalls);
    }
}
