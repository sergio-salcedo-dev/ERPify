<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Session\Application;

use DateTimeImmutable;
use Erpify\Iam\Session\Domain\Entity\Session;
use Erpify\Iam\Session\Domain\Enum\SessionStatus;
use Erpify\Iam\Session\Domain\SessionId;
use Erpify\Shared\Clock\Domain\SystemClock;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Unit\Iam\Session\Domain\Entity\Mother\SessionMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The double stands in for the Doctrine adapter across the use-case unit tests, so the reads it answers have
 * to mean what the port promises. These cases pin the four ways it can drift from the adapter without any
 * consumer noticing: the temporal predicate it applies, the order it lists in, whether a write is visible to a
 * later read, and — the one a bulk revocation turns on — which rows a write reaches and what it leaves on them.
 *
 * The bulk cases are deliberately more than "the reads stop answering". A revocation that only hid rows from
 * the two reads would satisfy every consumer that reads back through the port and still lie to
 * {@see InMemorySessionRepository::deleteRetired()}, which asks the entity itself for `status()` and
 * `revokedAt()`; and one selecting on admissibility rather than on status alone would spare exactly the rows
 * the adapter's `status = ACTIVE` filter does flip. Both directions are pinned below, along with the absence
 * of a domain event the un-hydrated UPDATE cannot produce.
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

    public function testFindByUserIdListsTheUsersSessionsNewestFirst(): void
    {
        $now = new DateTimeImmutable(self::NOW);
        SystemClock::set(new FixedClock($now));

        $oldest = SessionMother::active(id: Uuid::generate(), expiresAt: $now->modify('+1 hour'));
        $oldest->setCreatedAt($now->modify('-3 days'));

        $newest = SessionMother::active(id: Uuid::generate(), expiresAt: $now->modify('+1 hour'));
        $newest->setCreatedAt($now->modify('-1 day'));

        $middle = SessionMother::active(id: Uuid::generate(), expiresAt: $now->modify('+1 hour'));
        $middle->setCreatedAt($now->modify('-2 days'));

        // Preset in an order matching neither the expectation nor its reverse, so answering in insertion
        // order cannot pass by coincidence.
        $sessions = new InMemorySessionRepository($oldest, $newest, $middle);

        $this->assertSame([$newest, $middle, $oldest], $sessions->findByUserId(SessionMother::DEFAULT_USER_ID));
    }

    public function testFindByUserIdBreaksACreatedAtTieOnTheSessionId(): void
    {
        $now = new DateTimeImmutable(self::NOW);
        SystemClock::set(new FixedClock($now));

        // `created_at` is stored to the second, so two sessions minted within one second tie — two tabs, two
        // devices at login, a scripted client. Without a tiebreaker Postgres answers ties however the plan
        // runs while this double, whose sort is stable, would answer them in insertion order: deterministic
        // here and a coin flip in production, which is the divergence the mirroring exists to prevent.
        $lower = SessionMother::active(id: '0190c1d2-e3f4-7a5b-8c6d-000000000001', expiresAt: $now->modify('+1 hour'));
        $higher = SessionMother::active(id: '0190c1d2-e3f4-7a5b-8c6d-000000000002', expiresAt: $now->modify('+1 hour'));
        $lower->setCreatedAt($now);
        $higher->setCreatedAt($now);

        $sessions = new InMemorySessionRepository($lower, $higher);

        $this->assertSame([$higher, $lower], $sessions->findByUserId(SessionMother::DEFAULT_USER_ID));
    }

    public function testASessionExpiringOnThisVeryInstantIsInadmissible(): void
    {
        $now = new DateTimeImmutable(self::NOW);
        SystemClock::set(new FixedClock($now));
        $sessions = new InMemorySessionRepository(SessionMother::active(expiresAt: $now));

        $found = $sessions->findActiveById(SessionId::fromString(SessionMother::DEFAULT_ID));

        $this->assertNotInstanceOf(Session::class, $found);
    }

    public function testABulkRevocationTakesTheSessionOutOfBothReads(): void
    {
        SystemClock::set(new FixedClock(new DateTimeImmutable(self::NOW)));
        $sessions = new InMemorySessionRepository(SessionMother::active());

        $sessions->revokeAllForUser(SessionMother::DEFAULT_USER_ID);

        $this->assertNull($sessions->findActiveById(SessionId::fromString(SessionMother::DEFAULT_ID)));
        $this->assertSame([], $sessions->findByUserId(SessionMother::DEFAULT_USER_ID));
    }

    public function testRevokingTheOthersSparesTheSessionInHand(): void
    {
        $now = new DateTimeImmutable(self::NOW);
        SystemClock::set(new FixedClock($now));

        $current = SessionMother::active(expiresAt: $now->modify('+1 hour'));
        $other = SessionMother::active(id: Uuid::generate(), expiresAt: $now->modify('+1 hour'));
        $sessions = new InMemorySessionRepository($current, $other);

        $sessions->revokeOthersForUser(
            SessionMother::DEFAULT_USER_ID,
            SessionId::fromString(SessionMother::DEFAULT_ID),
        );

        $this->assertSame([$current], $sessions->findByUserId(SessionMother::DEFAULT_USER_ID));
        $this->assertSame(SessionStatus::ACTIVE, $current->status());
        $this->assertSame(SessionStatus::REVOKED, $other->status());
    }

    public function testABulkRevocationLeavesAnotherUsersSessionAlone(): void
    {
        $now = new DateTimeImmutable(self::NOW);
        SystemClock::set(new FixedClock($now));

        $otherUserId = Uuid::generate();
        $theirs = SessionMother::active(id: Uuid::generate(), userId: $otherUserId);
        $sessions = new InMemorySessionRepository(SessionMother::active(), $theirs);

        $sessions->revokeAllForUser(SessionMother::DEFAULT_USER_ID);

        $this->assertSame([$theirs], $sessions->findByUserId($otherUserId));
    }

    public function testABulkRevocationFlipsALapsedSessionThatIsStillActive(): void
    {
        $now = new DateTimeImmutable(self::NOW);
        SystemClock::set(new FixedClock($now));

        // Inadmissible by time and untouched in status — the row the adapter's `status = ACTIVE` filter does
        // reach, and the one a selection written against the reads' admissibility predicate would skip. It is
        // invisible to both reads either way, so nothing but its own state can tell the two selections apart.
        $lapsed = SessionMother::active(expiresAt: $now->modify('-1 hour'));
        // Backdated so the `updated_at` assertion below reads the revocation's stamp rather than the instant
        // the mint already wrote there, which every arm of this test would satisfy.
        $lapsed->setUpdatedAt($now->modify('-2 hours'));
        $sessions = new InMemorySessionRepository($lapsed);

        $sessions->revokeAllForUser(SessionMother::DEFAULT_USER_ID);

        $this->assertSame(SessionStatus::REVOKED, $lapsed->status());
        $this->assertEquals($now, $lapsed->revokedAt());
        $this->assertEquals($now, $lapsed->getUpdatedAt());
    }

    public function testABulkRevocationArmsTheRetentionBranchThatReadsTheStatus(): void
    {
        $now = new DateTimeImmutable(self::NOW);
        SystemClock::set(new FixedClock($now));
        $sessions = new InMemorySessionRepository(SessionMother::active());

        // Only the revocation branch can match: the default expiry is decades away, so `expiredBefore` here
        // never reaches it and a deletion is evidence of a stamped `revokedAt` rather than of a lapsed clock.
        $revokedBefore = $now->modify('+1 minute');
        $expiredBefore = $now;

        $this->assertSame(0, $sessions->deleteRetired($revokedBefore, $expiredBefore));

        $sessions->revokeAllForUser(SessionMother::DEFAULT_USER_ID);

        $this->assertSame(1, $sessions->deleteRetired($revokedBefore, $expiredBefore));
    }

    public function testABulkRevocationRecordsNoDomainEvent(): void
    {
        SystemClock::set(new FixedClock(new DateTimeImmutable(self::NOW)));
        $session = SessionMother::active();
        $sessions = new InMemorySessionRepository($session);

        // Drains the `SessionStarted` the mother's mint recorded, so what the assertion reads afterwards is
        // the revocation's own contribution and not a leftover.
        $session->pullDomainEvents();

        $sessions->revokeAllForUser(SessionMother::DEFAULT_USER_ID);

        $this->assertSame([], $session->pullDomainEvents());
    }
}
