<?php

declare(strict_types=1);

namespace Erpify\Iam\Session\Application;

use Erpify\Iam\Session\Domain\Repository\SessionRepository;
use Erpify\Shared\Uuid\Domain\Uuid;

/**
 * Hard-deletes every session row of a user — the published seam a GDPR identity erasure consumes to leave no
 * residual session PII (`ip`/`device`/`user_id`) behind a subject that no longer exists. The sibling
 * {@see RevokeAllSessions} soft-revokes (keeps the row) and emits an `AllSessionsRevoked` event; this one
 * deletes and emits nothing — a purged subject needs no "revoked" signal, and session observability
 * lives in PII-free events, so the row is disposable. It runs directed through the repository, so when the
 * caller wraps it in a transaction (the erasure does) the DELETE joins that unit of work and rolls back with it.
 */
final readonly class PurgeUserSessions
{
    public function __construct(
        private SessionRepository $sessions,
    ) {
    }

    public function purge(string $userId): int
    {
        Uuid::ensure($userId);

        return $this->sessions->deleteAllForUser($userId);
    }
}
