<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Session\Application;

use DateTimeImmutable;
use Erpify\Iam\Session\Domain\Entity\Session;
use Erpify\Iam\Session\Domain\SessionId;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Double\Clock\FixedClock;
use Erpify\Tests\Unit\Iam\Session\Domain\Entity\Mother\SessionMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The double stands in for the Doctrine adapter across the use-case unit tests, so the reads it answers have
 * to mean what the port promises. These cases pin the three ways a READ can drift from the adapter without any
 * consumer noticing: the temporal predicate it applies, the order it lists in, and whether a write is visible
 * to a later read. The fourth axis — which rows a bulk WRITE reaches and what it leaves on them — is pinned
 * in {@see InMemorySessionRepositoryBulkRevocationContractTest}, whose cases assert on held aggregates and so
 * carry a caveat these do not.
 *
 * @internal
 */
#[CoversClass(InMemorySessionRepository::class)]
final class InMemorySessionRepositoryContractTest extends TestCase
{
    private const string NOW = '2026-07-10T12:00:00+00:00';

    public function testASavedSessionIsVisibleToASubsequentRead(): void
    {
        $now = new DateTimeImmutable(self::NOW);
        $sessions = new InMemorySessionRepository();
        $sessions->clock = new FixedClock($now);

        $session = SessionMother::active(startedAt: $now);

        $sessions->save($session);

        $this->assertSame($session, $sessions->findActiveById(SessionId::fromString(SessionMother::DEFAULT_ID)));
    }

    public function testFindByUserIdReturnsOnlyTheUsersAdmissibleSessions(): void
    {
        $now = new DateTimeImmutable(self::NOW);

        $admissible = SessionMother::active(id: Uuid::generate(), expiresAt: $now->modify('+1 hour'), startedAt: $now);
        $lapsed = SessionMother::active(
            id: Uuid::generate(),
            expiresAt: $now->modify('-1 hour'),
            startedAt: $now->modify('-2 hours'),
        );
        $revoked = SessionMother::active(id: Uuid::generate(), expiresAt: $now->modify('+1 hour'), startedAt: $now);
        $revoked->revoke($now);

        $otherUser = SessionMother::active(
            id: Uuid::generate(),
            userId: Uuid::generate(),
            expiresAt: $now->modify('+1 hour'),
            startedAt: $now,
        );

        $sessions = new InMemorySessionRepository($admissible, $lapsed, $revoked, $otherUser);
        $sessions->clock = new FixedClock($now);

        $this->assertSame([$admissible], $sessions->findByUserId(SessionMother::DEFAULT_USER_ID));
    }

    public function testFindByUserIdListsTheUsersSessionsNewestFirst(): void
    {
        $now = new DateTimeImmutable(self::NOW);
        $expiresAt = $now->modify('+1 hour');

        $oldest = SessionMother::active(
            id: Uuid::generate(),
            expiresAt: $expiresAt,
            startedAt: $now->modify('-3 days'),
        );
        $newest = SessionMother::active(
            id: Uuid::generate(),
            expiresAt: $expiresAt,
            startedAt: $now->modify('-1 day'),
        );
        $middle = SessionMother::active(
            id: Uuid::generate(),
            expiresAt: $expiresAt,
            startedAt: $now->modify('-2 days'),
        );

        // Preset in an order matching neither the expectation nor its reverse, so answering in insertion
        // order cannot pass by coincidence.
        $sessions = new InMemorySessionRepository($oldest, $newest, $middle);
        $sessions->clock = new FixedClock($now);

        $this->assertSame([$newest, $middle, $oldest], $sessions->findByUserId(SessionMother::DEFAULT_USER_ID));
    }

    public function testFindByUserIdBreaksACreatedAtTieOnTheSessionId(): void
    {
        $now = new DateTimeImmutable(self::NOW);

        // `created_at` is stored to the second, so two sessions minted within one second tie — two tabs, two
        // devices at login, a scripted client. Without a tiebreaker Postgres answers ties however the plan
        // runs while this double, whose sort is stable, would answer them in insertion order: deterministic
        // here and a coin flip in production, which is the divergence the mirroring exists to prevent.
        $lower = SessionMother::active(
            id: '0190c1d2-e3f4-7a5b-8c6d-000000000001',
            expiresAt: $now->modify('+1 hour'),
            startedAt: $now,
        );
        $higher = SessionMother::active(
            id: '0190c1d2-e3f4-7a5b-8c6d-000000000002',
            expiresAt: $now->modify('+1 hour'),
            startedAt: $now,
        );

        $sessions = new InMemorySessionRepository($lower, $higher);
        $sessions->clock = new FixedClock($now);

        $this->assertSame([$higher, $lower], $sessions->findByUserId(SessionMother::DEFAULT_USER_ID));
    }

    public function testASessionExpiringOnThisVeryInstantIsInadmissible(): void
    {
        $now = new DateTimeImmutable(self::NOW);
        $sessions = new InMemorySessionRepository(
            SessionMother::active(expiresAt: $now, startedAt: $now->modify('-1 hour')),
        );
        $sessions->clock = new FixedClock($now);

        $found = $sessions->findActiveById(SessionId::fromString(SessionMother::DEFAULT_ID));

        $this->assertNotInstanceOf(Session::class, $found);
    }
}
