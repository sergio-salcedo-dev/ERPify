<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Session\Application;

use Erpify\Iam\Session\Domain\Entity\Session;
use Erpify\Iam\Session\Domain\Enum\SessionStatus;
use Erpify\Iam\Session\Domain\Repository\SessionRepository;
use Erpify\Iam\Session\Domain\SessionId;
use Override;
use RuntimeException;

/**
 * In-memory {@see SessionRepository} that records writes and answers the active-only reads, so a use-case test
 * can assert what a case persists, revokes in bulk, and reads back. The temporal predicate the real adapter
 * pushes into SQL is a store concern (covered by Behat); this fake filters on `status = ACTIVE` only.
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
            $id = $session->getId();

            if (null !== $id) {
                $this->byId[$id] = $session;
            }
        }
    }

    #[Override]
    public function save(Session $session): void
    {
        $this->saved[] = $session;
    }

    #[Override]
    public function findActiveById(SessionId $id): ?Session
    {
        $session = $this->byId[$id->toString()] ?? null;

        if (!$session instanceof Session) {
            return null;
        }

        return SessionStatus::ACTIVE === $session->status() ? $session : null;
    }

    #[Override]
    public function findByUserId(string $userId): array
    {
        return \array_values(\array_filter(
            $this->byId,
            static fn (Session $s): bool => $s->userId() === $userId && SessionStatus::ACTIVE === $s->status(),
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
}
