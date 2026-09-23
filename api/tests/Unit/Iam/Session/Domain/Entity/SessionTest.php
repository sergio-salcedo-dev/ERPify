<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Session\Domain\Entity;

use DateTimeImmutable;
use Erpify\Iam\Session\Domain\Entity\Session;
use Erpify\Iam\Session\Domain\Enum\SessionStatus;
use Erpify\Iam\Session\Domain\Event\SessionRevoked;
use Erpify\Iam\Session\Domain\Event\SessionStarted;
use Erpify\Iam\Session\Domain\Exception\InvalidSessionTransition;
use Erpify\Shared\Uuid\Domain\InvalidUuidException;
use Erpify\Tests\Double\Clock\SuiteInstant;
use Erpify\Tests\Unit\Iam\Session\Domain\Entity\Mother\SessionMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The session lifecycle machine: `ACTIVE → REVOKED` (single, terminal) plus the orthogonal temporal-validity
 * predicate. Every instant is handed in by the test, so each stamp asserted below is one the test chose.
 *
 * @internal
 */
#[CoversClass(Session::class)]
final class SessionTest extends TestCase
{
    public function testStartMintsAnActiveSessionRecordingSessionStarted(): void
    {
        $startedAt = new DateTimeImmutable('2029-12-25T00:00:00+00:00');
        $expiresAt = new DateTimeImmutable('2030-01-01T00:00:00+00:00');
        $session = SessionMother::active(expiresAt: $expiresAt, startedAt: $startedAt);

        $this->assertSame(SessionStatus::ACTIVE, $session->status());
        $this->assertSame($startedAt, $session->getCreatedAt());
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
        $session = SessionMother::active(startedAt: $now->modify('-1 hour'));
        $session->pullDomainEvents();

        $session->revoke($now);

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
        $now = SuiteInstant::now();
        $session = SessionMother::active(startedAt: $now);
        $session->revoke($now);
        $session->pullDomainEvents();

        try {
            $session->revoke($now);
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
        $session = SessionMother::active(expiresAt: $expiresAt, startedAt: $expiresAt->modify('-7 days'));

        $this->assertTrue($session->isExpired($expiresAt));
        $this->assertTrue($session->isExpired($expiresAt->modify('+1 second')));
        $this->assertFalse($session->isExpired($expiresAt->modify('-1 second')));
    }

    public function testIsActiveRequiresActiveStatusAndAnUnreachedExpiry(): void
    {
        $expiresAt = new DateTimeImmutable('2026-07-10T10:00:00+00:00');
        $beforeExpiry = $expiresAt->modify('-1 hour');
        $session = SessionMother::active(expiresAt: $expiresAt, startedAt: $expiresAt->modify('-7 days'));

        $this->assertTrue($session->isActive($beforeExpiry));
        $this->assertFalse($session->isActive($expiresAt), 'an expired session is not active even while ACTIVE');

        $session->revoke($beforeExpiry);
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
