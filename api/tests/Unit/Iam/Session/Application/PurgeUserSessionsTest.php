<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Session\Application;

use DateTimeImmutable;
use Erpify\Iam\Session\Application\PurgeUserSessions;
use Erpify\Iam\Session\Domain\Entity\Session;
use Erpify\Iam\Session\Domain\SessionId;
use Erpify\Shared\Uuid\Domain\InvalidUuidException;
use Erpify\Shared\Uuid\Domain\Uuid;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The published seam a GDPR erasure consumes to drop a subject's sessions. It hard-deletes every row of the
 * user (scoped to them, never another's) and guards the id shape before touching the store.
 *
 * @internal
 */
#[CoversClass(PurgeUserSessions::class)]
final class PurgeUserSessionsTest extends TestCase
{
    public function testHardDeletesEverySessionOfTheUserAndReturnsTheCount(): void
    {
        $userId = Uuid::generate();
        $sessions = new InMemorySessionRepository(
            $this->sessionFor($userId),
            $this->sessionFor($userId),
            $this->sessionFor(Uuid::generate()),
        );

        $deleted = (new PurgeUserSessions($sessions))->purge($userId);

        $this->assertSame(2, $deleted);
        $this->assertSame([$userId], $sessions->deleteAllCalls);
    }

    public function testRejectsAMalformedUserIdBeforeTouchingTheStore(): void
    {
        $sessions = new InMemorySessionRepository();

        $this->expectException(InvalidUuidException::class);

        (new PurgeUserSessions($sessions))->purge('not-a-uuid');
    }

    private function sessionFor(string $userId): Session
    {
        $session = Session::start(
            SessionId::generate()->toString(),
            $userId,
            Uuid::generate(),
            'Test client',
            '127.0.0.1',
            new DateTimeImmutable('2026-07-22T13:00:00+00:00'),
        );
        $session->pullDomainEvents();

        return $session;
    }
}
