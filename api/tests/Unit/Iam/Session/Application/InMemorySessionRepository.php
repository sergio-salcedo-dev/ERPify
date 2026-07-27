<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Session\Application;

use Erpify\Iam\Session\Domain\Entity\Session;
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
 * multi-row read cannot straddle the boundary mid-scan.
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

    /** @var list<string> userIds passed to revokeOthersForUser */
    public array $revokeOthersCalls = [];

    /** @var list<string> userIds passed to revokeAllForUser */
    public array $revokeAllCalls = [];

    /** @var list<string> userIds passed to deleteAllForUser */
    public array $deleteAllCalls = [];

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

        return \array_values(\array_filter(
            $this->byId,
            static fn (Session $s): bool => $s->userId() === $userId && $s->isActive($now),
        ));
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

    private function index(Session $session): void
    {
        $id = $session->getId();

        if (null !== $id) {
            $this->byId[$id] = $session;
        }
    }
}
