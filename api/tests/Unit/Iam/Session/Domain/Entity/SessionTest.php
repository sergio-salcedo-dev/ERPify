<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Session\Domain\Entity;

use DateTimeImmutable;
use Erpify\Iam\Session\Domain\Entity\Session;
use Erpify\Iam\Session\Domain\Enum\SessionStatus;
use Erpify\Iam\Session\Domain\Event\SessionRevoked;
use Erpify\Iam\Session\Domain\Event\SessionStarted;
use Erpify\Iam\Session\Domain\Exception\InvalidSessionTransition;
use Erpify\Shared\Clock\Domain\SystemClock;
use Erpify\Shared\Uuid\Domain\InvalidUuidException;
use Erpify\Tests\Unit\Iam\Session\Application\FixedClock;
use Erpify\Tests\Unit\Iam\Session\Domain\Entity\Mother\SessionMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The session lifecycle machine: `ACTIVE → REVOKED` (single, terminal) plus the orthogonal temporal-validity
 * predicate. `SystemClock` is frozen for the revocation timestamp; the auto-reset extension restores it.
 *
 * @internal
 */
#[CoversClass(Session::class)]
final class SessionTest extends TestCase
{
    public function testStartMintsAnActiveSessionRecordingSessionStarted(): void
    {
        $expiresAt = new DateTimeImmutable('2030-01-01T00:00:00+00:00');
        $session = SessionMother::active(expiresAt: $expiresAt);

        $this->assertSame(SessionStatus::ACTIVE, $session->status());
        $this->assertSame(SessionMother::DEFAULT_USER_ID, $session->userId());
        $this->assertSame(SessionMother::DEFAULT_ORG_ID, $session->organizationId());
        $this->assertSame(SessionMother::DEFAULT_DEVICE, $session->device());
        $this->assertSame(SessionMother::DEFAULT_IP, $session->ip());
        $this->assertSame($expiresAt, $session->expiresAt());
        $this->assertNotInstanceOf(DateTimeImmutable::class, $session->revokedAt());

        $events = $session->pullDomainEvents();
        $this->assertCount(1, $events);
        $started = $events[0];
        $this->assertInstanceOf(SessionStarted::class, $started);
        $this->assertSame(SessionMother::DEFAULT_ID, $started->aggregateId());
        $this->assertSame(SessionMother::DEFAULT_USER_ID, $started->userId());
    }

    public function testRevokeMovesActiveToRevokedRecordingSessionRevoked(): void
    {
        $now = new DateTimeImmutable('2026-07-10T09:30:00+00:00');
        SystemClock::set(new FixedClock($now));
        $session = SessionMother::active();
        $session->pullDomainEvents();

        $session->revoke();

        $this->assertSame(SessionStatus::REVOKED, $session->status());
        $this->assertSame($now, $session->revokedAt());
        $this->assertFalse($session->isActive($now));

        $events = $session->pullDomainEvents();
        $this->assertCount(1, $events);
        $revoked = $events[0];
        $this->assertInstanceOf(SessionRevoked::class, $revoked);
        $this->assertSame(SessionMother::DEFAULT_ID, $revoked->aggregateId());
        $this->assertSame(SessionMother::DEFAULT_USER_ID, $revoked->userId());
    }

    public function testAnAlreadyRevokedSessionRejectsASecondRevokeWithoutFurtherMutation(): void
    {
        $session = SessionMother::active();
        $session->revoke();
        $session->pullDomainEvents();

        try {
            $session->revoke();
            $this->fail('Expected the terminal transition to reject a second revoke.');
        } catch (InvalidSessionTransition) {
            // the guard runs before the aggregate mutates or records anything
        }

        $this->assertSame(SessionStatus::REVOKED, $session->status());
        $this->assertSame([], $session->pullDomainEvents());
    }

    public function testIsExpiredIsTrueOnceNowReachesTheAbsoluteExpiry(): void
    {
        $expiresAt = new DateTimeImmutable('2026-07-10T10:00:00+00:00');
        $session = SessionMother::active(expiresAt: $expiresAt);

        $this->assertTrue($session->isExpired($expiresAt));
        $this->assertTrue($session->isExpired($expiresAt->modify('+1 second')));
        $this->assertFalse($session->isExpired($expiresAt->modify('-1 second')));
    }

    public function testIsActiveRequiresActiveStatusAndAnUnreachedExpiry(): void
    {
        $expiresAt = new DateTimeImmutable('2026-07-10T10:00:00+00:00');
        $beforeExpiry = $expiresAt->modify('-1 hour');
        $session = SessionMother::active(expiresAt: $expiresAt);

        $this->assertTrue($session->isActive($beforeExpiry));
        $this->assertFalse($session->isActive($expiresAt), 'an expired session is not active even while ACTIVE');

        $session->revoke();
        $this->assertFalse($session->isActive($beforeExpiry), 'a revoked session is not active even before expiry');
    }

    public function testStartRejectsAMalformedUserId(): void
    {
        $this->expectException(InvalidUuidException::class);

        SessionMother::active(userId: 'not-a-uuid');
    }

    public function testStartRejectsAMalformedOrganizationId(): void
    {
        $this->expectException(InvalidUuidException::class);

        SessionMother::active(organizationId: 'not-a-uuid');
    }
}
