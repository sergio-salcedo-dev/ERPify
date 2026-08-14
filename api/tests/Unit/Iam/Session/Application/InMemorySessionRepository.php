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
    }

    #[Override]
    public function revokeAllForUser(string $userId): void
    {
        $this->revokeAllCalls[] = $userId;

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

    private function index(Session $session): void
    {
        $id = $session->getId();

        if (null !== $id) {
            $this->byId[$id] = $session;
        }
    }
}
