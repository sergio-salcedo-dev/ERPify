<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Session\Application;

use DateTimeImmutable;
use Erpify\Iam\Session\Domain\Entity\Session;
use Erpify\Iam\Session\Domain\SessionId;
use Erpify\Shared\Clock\Domain\SystemClock;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Unit\Iam\Session\Domain\Entity\Mother\SessionMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The double stands in for the Doctrine adapter across the use-case unit tests, so the reads it answers have
 * to mean what the port promises. These cases pin the two ways it can drift from the adapter without any
 * consumer noticing: the temporal predicate it applies, and whether a write is visible to a later read.
 *
 * @internal
 */
#[CoversClass(InMemorySessionRepository::class)]
final class InMemorySessionRepositoryContractTest extends TestCase
{
    private const string NOW = '2026-07-10T12:00:00+00:00';

    public function testASavedSessionIsVisibleToASubsequentRead(): void
    {
        SystemClock::set(new FixedClock(new DateTimeImmutable(self::NOW)));
        $sessions = new InMemorySessionRepository();
        $session = SessionMother::active();

        $sessions->save($session);

        $this->assertSame($session, $sessions->findActiveById(SessionId::fromString(SessionMother::DEFAULT_ID)));
    }

    public function testFindByUserIdReturnsOnlyTheUsersAdmissibleSessions(): void
    {
        $now = new DateTimeImmutable(self::NOW);
        SystemClock::set(new FixedClock($now));

        $admissible = SessionMother::active(id: Uuid::generate(), expiresAt: $now->modify('+1 hour'));
        $lapsed = SessionMother::active(id: Uuid::generate(), expiresAt: $now->modify('-1 hour'));
        $revoked = SessionMother::active(id: Uuid::generate(), expiresAt: $now->modify('+1 hour'));
        $revoked->revoke();

        $otherUser = SessionMother::active(
            id: Uuid::generate(),
            userId: Uuid::generate(),
            expiresAt: $now->modify('+1 hour'),
        );

        $sessions = new InMemorySessionRepository($admissible, $lapsed, $revoked, $otherUser);

        $this->assertSame([$admissible], $sessions->findByUserId(SessionMother::DEFAULT_USER_ID));
    }

    public function testASessionExpiringOnThisVeryInstantIsInadmissible(): void
    {
        $now = new DateTimeImmutable(self::NOW);
        SystemClock::set(new FixedClock($now));
        $sessions = new InMemorySessionRepository(SessionMother::active(expiresAt: $now));

        $found = $sessions->findActiveById(SessionId::fromString(SessionMother::DEFAULT_ID));

        $this->assertNotInstanceOf(Session::class, $found);
    }
}
