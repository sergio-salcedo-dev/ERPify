<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Session\Infrastructure\Security;

use Erpify\Iam\Session\Application\RevokeSession;
use Erpify\Iam\Session\Domain\Enum\SessionStatus;
use Erpify\Iam\Session\Domain\SessionId;
use Erpify\Iam\Session\Infrastructure\Security\RevokeSessionOnTokenDeauthenticated;
use Erpify\Tests\Unit\Iam\Session\Application\InlineTransactionManager;
use Erpify\Tests\Unit\Iam\Session\Application\InMemorySessionRepository;
use Erpify\Tests\Unit\Iam\Session\Application\RecordingCurrentSessionReference;
use Erpify\Tests\Unit\Iam\Session\Application\RecordingEventBus;
use Erpify\Tests\Unit\Iam\Session\Domain\Entity\Mother\SessionMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The reactor binds to {@see \Symfony\Component\Security\Http\Event\TokenDeauthenticatedEvent} through its
 * `#[AsEventListener]` attribute and reads only the correlation port, so its behaviour is exercised by invoking
 * it directly — it needs nothing off the event.
 *
 * @internal
 */
#[CoversClass(RevokeSessionOnTokenDeauthenticated::class)]
final class RevokeSessionOnTokenDeauthenticatedTest extends TestCase
{
    public function testItRevokesTheCorrelatedSessionOnNativeDeauthentication(): void
    {
        $session = SessionMother::active();
        $session->pullDomainEvents();

        $sessions = new InMemorySessionRepository($session);
        $reactor = new RevokeSessionOnTokenDeauthenticated(
            new RecordingCurrentSessionReference(SessionId::fromString(SessionMother::DEFAULT_ID)),
            new RevokeSession($sessions, new RecordingEventBus(), new InlineTransactionManager()),
        );

        $reactor();

        $this->assertSame([$session], $sessions->saved);
        $this->assertSame(SessionStatus::REVOKED, $session->status());
    }

    public function testItIsANoOpWhenThereIsNoCorrelatedSession(): void
    {
        $sessions = new InMemorySessionRepository();
        $reactor = new RevokeSessionOnTokenDeauthenticated(
            new RecordingCurrentSessionReference(),
            new RevokeSession($sessions, new RecordingEventBus(), new InlineTransactionManager()),
        );

        $reactor();

        $this->assertSame([], $sessions->saved);
    }
}
