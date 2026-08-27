<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Session\Application;

use Closure;
use DateTimeImmutable;
use Erpify\Iam\Session\Domain\Entity\Session;
use Erpify\Iam\Session\Domain\Enum\SessionStatus;
use Erpify\Iam\Session\Domain\Repository\SessionRepository;
use Erpify\Iam\Session\Domain\SessionId;
use Erpify\Shared\Clock\Domain\SystemClock;
use Override;
use ReflectionProperty;
use RuntimeException;

/**
 * In-memory {@see SessionRepository} that records writes and answers the active-only reads, so a use-case test
 * can assert what a case persists, revokes in bulk, and reads back.
 *
 * Admissibility is delegated to {@see Session::isActive()} instead of being re-expressed here: the port
 * publishes `status = ACTIVE AND expires_at > now` as a postcondition of its reads, and a fake looser than the
 * real adapter lets a use-case test assert behaviour production cannot produce. Delegating leaves two
 * renderings of the rule — this one and the adapter's DQL, which is what actually runs — instead of three.
 * They coincide at whole-second granularity, which is all `expires_at TIMESTAMP(0)` preserves anyway.
 *
 * `now` is read once per query, mirroring the single `:now` the adapter binds for a whole statement, so a
 * multi-row read cannot straddle the boundary mid-scan. The listing's newest-first order is mirrored for the
 * same reason the predicate is: it is a promise of the port, so a double that answered in another order would
 * let a use-case test assert a sequence production cannot produce.
 *
 * The instant comes from {@see SystemClock} rather than an injected {@see \Erpify\Shared\Clock\Domain\Clock}:
 * the constructor is variadic, so a clock parameter would have to precede the presets, and no consumer has yet
 * needed to freeze one here. A test that does freezes it exactly as it already freezes the aggregate's own.
 *
 * **The bulk revocations mutate, and what they mirror is the adapter's directed UPDATE — not the aggregate's
 * own {@see Session::revoke()}.** The two write different things and only one of them is what a consumer of
 * this port can observe in production. Three points fix the shape:
 *
 *   - **Selection is `status = ACTIVE` alone, never {@see Session::isActive()}.** The UPDATE carries the
 *     lifecycle half of admissibility and not the temporal one, so an expired-but-still-`ACTIVE` row IS
 *     flipped. Reusing the reads' predicate here would flip a strictly narrower set than production and leave
 *     a lapsed row still reporting `ACTIVE` — visible to {@see deleteRetired()}, which reads the status.
 *   - **No domain event is recorded.** The UPDATE runs without hydrating the aggregates, so a bulk revocation
 *     produces none. A double calling `revoke()` per row would leave a `SessionRevoked` in the aggregate's
 *     pending list for whoever pulls next, letting a use-case test assert an event production cannot produce
 *     — the same defect as a looser read predicate, one direction over. Draining the aggregate afterwards is
 *     not an answer either: {@see Session::pullDomainEvents()} empties the whole list, including a
 *     `SessionStarted` its owner has not published yet.
 *   - **The flip is written into the entity, because the entity IS this double's storage.** A set of revoked
 *     ids beside it would be two sources of truth for one column, and every reader would have to remember to
 *     consult both — {@see deleteRetired()} included, which asks the entity for `status()` and `revokedAt()`
 *     and would otherwise go on seeing an `ACTIVE` row with no `revokedAt`, leaving the retention branch a
 *     revocation exists to arm unfired.
 *     **The accepted cost is stated rather than argued away:** this makes the double MORE observable than
 *     production on one axis. Doctrine does not refresh its identity map from a bulk UPDATE, so a caller
 *     holding an already-hydrated `Session` still reads `ACTIVE` off it after the real statement runs, while
 *     here it reads `REVOKED`. Assert through the port's reads, never off a held aggregate — an assertion on
 *     a held object is the one shape this double answers differently from the adapter it mirrors.
 *
 * Writing those two columns takes reflection, because the aggregate publishes exactly one guarded transition
 * and no seam for anything else — deliberately. That is the faithful mirror rather than a shortcut: the
 * adapter reaches past the aggregate too, and the alternative is widening the domain's write surface for a
 * double's convenience. `now` is read once per call, mirroring the single `:now` the UPDATE binds to
 * `revoked_at` and `updated_at` alike.
 *
 * @internal
 */
final class InMemorySessionRepository implements SessionRepository
{
    /** @var list<Session> */
    public array $saved = [];

    /**
     * Invoked inside {@see revokeAllForUser()}, so a test can observe the surrounding state at that exact
     * instant instead of inferring it from a later effect. It is a property rather than a constructor
     * parameter for the same reason the clock is not one: the constructor is variadic over the presets.
     */
    public ?Closure $onRevokeAll = null;

    /** @var list<string> userIds passed to revokeOthersForUser */
    public array $revokeOthersCalls = [];

    /** @var list<string> userIds passed to revokeAllForUser */
    public array $revokeAllCalls = [];

    /** @var list<string> userIds passed to deleteAllForUser */
    public array $deleteAllCalls = [];

    /** @var list<array{revokedBefore: DateTimeImmutable, expiredBefore: DateTimeImmutable}> */
    public array $deleteRetiredCalls = [];

    /** Makes deleteAllForUser throw, so a test can drive the "a failed session purge aborts the erasure" path. */
    public bool $failOnDelete = false;

    public ?SessionId $lastRevokeOthersexcept = null;

    /** @var array<string, Session> */
    private array $byId = [];

    public function __construct(Session ...$preset)
    {
        foreach ($preset as $session) {
            $this->index($session);
        }
    }

    #[Override]
    public function save(Session $session): void
    {
        $this->saved[] = $session;
        $this->index($session);
    }

    #[Override]
    public function findActiveById(SessionId $id): ?Session
    {
        $session = $this->byId[$id->toString()] ?? null;

        if (!$session instanceof Session) {
            return null;
        }

        return $session->isActive(SystemClock::now()) ? $session : null;
    }

    #[Override]
    public function findByUserId(string $userId): array
    {
        $now = SystemClock::now();

        $admissible = \array_values(\array_filter(
            $this->byId,
            static fn (Session $s): bool => $s->userId() === $userId && $s->isActive($now),
        ));

        // Newest first, mirroring the adapter's `ORDER BY s.createdAt DESC, s.id DESC`. Answering in insertion
        // order instead would let a use-case test assert a sequence production cannot produce — the same way a
        // looser temporal predicate would, which is why the ordering is mirrored rather than left to chance.
        //
        // The id tiebreaker is mirrored too, and it is the half that would rot unnoticed: PHP's sort is stable,
        // so without it this double answers ties in insertion order — deterministically, while Postgres answers
        // them however the plan runs. A double that is MORE ordered than production is the same defect as one
        // that is less strict about admissibility.
        \usort(
            $admissible,
            static fn (Session $a, Session $b): int => [$b->getCreatedAt(), $b->getId()]
                <=> [$a->getCreatedAt(), $a->getId()],
        );

        return $admissible;
    }

    #[Override]
    public function revokeOthersForUser(string $userId, SessionId $currentSessionId): void
    {
        $this->revokeOthersCalls[] = $userId;
        $this->lastRevokeOthersexcept = $currentSessionId;

        $this->bulkRevokeActive($userId, $currentSessionId);
    }

    #[Override]
    public function revokeAllForUser(string $userId): void
    {
        $this->revokeAllCalls[] = $userId;

        $this->bulkRevokeActive($userId, null);

        // Fired after the flip so the hook observes the store the revocation left behind, which is the state
        // a caller reasoning about "the sessions are gone by now" would read.
        if ($this->onRevokeAll instanceof Closure) {
            ($this->onRevokeAll)();
        }
    }

    #[Override]
    public function deleteAllForUser(string $userId): int
    {
        $this->deleteAllCalls[] = $userId;

        if ($this->failOnDelete) {
            throw new RuntimeException('Session store unavailable during purge.');
        }

        $deleted = 0;

        foreach ($this->byId as $key => $session) {
            if ($session->userId() === $userId) {
                unset($this->byId[$key]);
                ++$deleted;
            }
        }

        return $deleted;
    }

    #[Override]
    public function deleteRetired(DateTimeImmutable $revokedBefore, DateTimeImmutable $expiredBefore): int
    {
        $this->deleteRetiredCalls[] = ['revokedBefore' => $revokedBefore, 'expiredBefore' => $expiredBefore];

        $deleted = 0;

        foreach ($this->byId as $key => $session) {
            if ($this->isRetired($session, $revokedBefore, $expiredBefore)) {
                unset($this->byId[$key]);
                ++$deleted;
            }
        }

        return $deleted;
    }

    /**
     * Mirrors the adapter's two branches, whichever elapses first. Only the revocation branch is conditioned
     * on a status, exactly as the DQL is: the expiry branch applies to every row, so a revocation cannot
     * lengthen the life of a row that lapsed earlier and no future status can fall through both.
     *
     * A revoked row with no `revokedAt` fails the first branch here as `NULL < :threshold` fails it in SQL,
     * and reaches the second one on its own clock.
     */
    private function isRetired(
        Session $session,
        DateTimeImmutable $revokedBefore,
        DateTimeImmutable $expiredBefore,
    ): bool {
        $revokedAt = $session->revokedAt();

        if (
            SessionStatus::REVOKED === $session->status()
            && $revokedAt instanceof DateTimeImmutable
            && $revokedAt < $revokedBefore
        ) {
            return true;
        }

        return $session->expiresAt() < $expiredBefore;
    }

    /**
     * Flips every currently-`ACTIVE` session of the user, optionally sparing the one in hand. The `$except`
     * comparison is on the id string rather than {@see SessionId::equals()} because what is indexed is the
     * entity's own nullable id, and a session that never got one is not addressable by the adapter either.
     */
    private function bulkRevokeActive(string $userId, ?SessionId $except): void
    {
        $now = SystemClock::now();
        $spared = $except?->toString();

        foreach ($this->byId as $id => $session) {
            // Both comparisons are case-insensitive because the adapter's are: `user_id` and `id` are
            // `Types::GUID`, which in PostgreSQL is the native `uuid` type, and `uuid` equality normalises
            // hex case. A `===` here would diverge from production in both directions at once — an
            // upper-case `$userId` would make this double revoke NOTHING where the adapter revokes
            // everything, and an upper-case spared id would make it revoke the very session the caller
            // asked to keep. Unreachable while `Uuid::generate()` emits lower case and ids are
            // server-written, and mirrored anyway: a double that is stricter than what it stands in for
            // lets a use-case test assert behaviour production cannot produce, which is the one thing
            // this class exists not to do.
            if (0 !== \strcasecmp($session->userId(), $userId) || SessionStatus::ACTIVE !== $session->status()) {
                continue;
            }

            if (null !== $spared && 0 === \strcasecmp($id, $spared)) {
                continue;
            }

            $this->flipToRevoked($session, $now);
        }
    }

    /**
     * Writes the three columns the UPDATE sets, and nothing else — no guard, because the statement has none
     * beyond the `status = ACTIVE` filter that already selected this row, and no event, because nothing was
     * hydrated to record one.
     */
    private function flipToRevoked(Session $session, DateTimeImmutable $now): void
    {
        // Parenthesised rather than PHP 8.4's bare `new X()->…`: PDepend, which PHPMD parses with, cannot
        // read that form, and the repo spells it this way everywhere for the same reason.
        (new ReflectionProperty(Session::class, 'status'))->setValue($session, SessionStatus::REVOKED);
        (new ReflectionProperty(Session::class, 'revokedAt'))->setValue($session, $now);
        $session->setUpdatedAt($now);
    }

    private function index(Session $session): void
    {
        $id = $session->getId();

        if (null !== $id) {
            $this->byId[$id] = $session;
        }
    }
}
