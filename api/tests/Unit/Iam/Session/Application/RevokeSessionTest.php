<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Session\Application;

use DateTimeImmutable;
use Erpify\Iam\Session\Application\RevokeSession;
use Erpify\Iam\Session\Domain\Enum\SessionStatus;
use Erpify\Iam\Session\Domain\Event\SessionRevoked;
use Erpify\Iam\Session\Domain\SessionId;
use Erpify\Tests\Unit\Iam\Session\Domain\Entity\Mother\SessionMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(RevokeSession::class)]
final class RevokeSessionTest extends TestCase
{
    public function testRevokesAnActiveSessionAndPublishesSessionRevoked(): void
    {
        $session = SessionMother::active();
        $session->pullDomainEvents();

        $sessions = new InMemorySessionRepository($session);
        $eventBus = new RecordingEventBus();
        $revokeSession = new RevokeSession($sessions, $eventBus, new InlineTransactionManager());

        $revokeSession->revoke(SessionId::fromString(SessionMother::DEFAULT_ID));

        $this->assertSame([$session], $sessions->saved);
        $this->assertSame(SessionStatus::REVOKED, $session->status());
        $this->assertCount(1, $eventBus->publishedEvents);
        $this->assertInstanceOf(SessionRevoked::class, $eventBus->publishedEvents[0]);
    }

    public function testRevokingAnAlreadyInertSessionIsANoOp(): void
    {
        $sessions = new InMemorySessionRepository();
        $eventBus = new RecordingEventBus();
        $revokeSession = new RevokeSession($sessions, $eventBus, new InlineTransactionManager());

        $revokeSession->revoke(SessionId::generate());

        $this->assertSame([], $sessions->saved);
        $this->assertSame([], $eventBus->publishedEvents);
    }

    public function testRevokingATimeExpiredSessionIsANoOp(): void
    {
        $session = SessionMother::active(expiresAt: new DateTimeImmutable('2020-01-01T00:00:00+00:00'));
        $session->pullDomainEvents();

        $sessions = new InMemorySessionRepository($session);
        $eventBus = new RecordingEventBus();
        $revokeSession = new RevokeSession($sessions, $eventBus, new InlineTransactionManager());

        $revokeSession->revoke(SessionId::fromString(SessionMother::DEFAULT_ID));

        $this->assertSame([], $sessions->saved);
        $this->assertSame([], $eventBus->publishedEvents);
        $this->assertSame(SessionStatus::ACTIVE, $session->status(), 'a lapsed session is never transitioned');
    }
}
