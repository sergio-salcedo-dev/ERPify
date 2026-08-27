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
 * The bulk revocations are the half of the double that WRITES, and what they mirror is the adapter's directed
 * UPDATE rather than the aggregate's own guarded transition. They sit apart from the read contract in
 * {@see InMemorySessionRepositoryContractTest} because the drift they can hide is a different one: not which
 * rows a read admits, but which rows a write reaches and what it leaves on them.
 *
 * The cases are deliberately more than "the reads stop answering". A revocation that only hid rows from the
 * two reads would satisfy every consumer that reads back through the port and still lie to
 * {@see InMemorySessionRepository::deleteRetired()}, which asks the entity itself for `status()` and
 * `revokedAt()`; and one selecting on admissibility rather than on status alone would spare exactly the rows
 * the adapter's `status = ACTIVE` filter does flip. Both directions are pinned below, along with the absence
 * of a domain event the un-hydrated UPDATE cannot produce.
 *
 * **These cases assert on held aggregates, and that is the one place this class is not a template.** They do
 * it to reach state no read exposes — a lapsed row's `status`, a `revokedAt` only `deleteRetired()` looks at.
 * It is safe here because the subject under test IS the double. It is not safe to copy into a use-case test:
 * Doctrine does not refresh its identity map from a bulk UPDATE, so an aggregate a caller already holds still
 * reads `ACTIVE` in production after the real statement runs. `assertFalse($held->isActive($now))` is
 * therefore green here and false against the adapter. Use-case tests assert through the port's reads.
 *
 * @internal
 */
#[CoversClass(InMemorySessionRepository::class)]
final class InMemorySessionRepositoryBulkRevocationContractTest extends TestCase
{
    private const string NOW = '2026-07-10T12:00:00+00:00';

    public function testABulkRevocationTakesTheSessionOutOfBothReads(): void
    {
        SystemClock::set(new FixedClock(new DateTimeImmutable(self::NOW)));
        $sessions = new InMemorySessionRepository(SessionMother::active());

        $sessions->revokeAllForUser(SessionMother::DEFAULT_USER_ID);

        $this->assertNotInstanceOf(
            Session::class,
            $sessions->findActiveById(SessionId::fromString(SessionMother::DEFAULT_ID)),
        );
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

    /**
     * Postgres compares `uuid` without regard to hex case, so the double must too — in both directions, and
     * they fail opposite ways. A `===` on the user id makes a bulk revocation reach nothing where the adapter
     * reaches everything; a `===` on the spared id revokes the one session the caller asked to keep.
     */
    public function testABulkRevocationComparesIdsTheWayPostgresCompares(): void
    {
        $now = new DateTimeImmutable(self::NOW);
        SystemClock::set(new FixedClock($now));

        $current = SessionMother::active(expiresAt: $now->modify('+1 hour'));
        $other = SessionMother::active(id: Uuid::generate(), expiresAt: $now->modify('+1 hour'));
        $sessions = new InMemorySessionRepository($current, $other);

        $sessions->revokeOthersForUser(
            \strtoupper(SessionMother::DEFAULT_USER_ID),
            SessionId::fromString(\strtoupper(SessionMother::DEFAULT_ID)),
        );

        $this->assertSame(SessionStatus::ACTIVE, $current->status(), 'the spared session is spared');
        $this->assertSame(SessionStatus::REVOKED, $other->status(), 'every other session is still reached');
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
        $this->assertSame($now, $lapsed->revokedAt());
        $this->assertSame($now, $lapsed->getUpdatedAt());
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
