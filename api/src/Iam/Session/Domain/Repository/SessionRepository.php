<?php

declare(strict_types=1);

namespace Erpify\Iam\Session\Domain\Repository;

use Erpify\Iam\Session\Domain\Entity\Session;
use Erpify\Iam\Session\Domain\SessionId;

/**
 * Aggregate-lifecycle port for {@see Session} backed by the server-side registry.
 *
 * The temporal-validity predicate (`status = ACTIVE AND expires_at > now`) lives in the read queries, not in a
 * persisted state: {@see findActiveById()} and {@see findByUserId()} both apply it, so an expired session is
 * invisible to the gate and to "my sessions" without any sweeper writing a transition. The bulk revocations run
 * as directed UPDATEs (no aggregate hydration), which is why they live on the port rather than looping the
 * aggregate.
 *
 * The adapter converts a store-connection failure into a domain {@see
 * \Erpify\Iam\Session\Domain\Exception\SessionStoreUnavailable} (→ 503) so the gate can fail closed rather than
 * letting a raw infrastructure error surface as a 500.
 */
interface SessionRepository
{
    public function save(Session $session): void;

    /**
     * Returns the session only when it is admissible now — `status = ACTIVE AND expires_at > now`. A revoked,
     * expired or absent session resolves to `null`, which the gate maps to the 401 re-login outcome.
     *
     * @throws \Erpify\Iam\Session\Domain\Exception\SessionStoreUnavailable when the store is unreachable
     */
    public function findActiveById(SessionId $id): ?Session;

    /**
     * The user's currently-admissible sessions, for the "my sessions" projection (same temporal predicate).
     *
     * @return list<Session>
     */
    public function findByUserId(string $userId): array;

    /**
     * Bulk-revokes every currently-active session of the user EXCEPT `$currentSessionId` — the "close the
     * others" action, which never self-expels the session in hand.
     */
    public function revokeOthersForUser(string $userId, SessionId $currentSessionId): void;

    /**
     * Bulk-revokes every currently-active session of the user (the reset-everywhere capability).
     */
    public function revokeAllForUser(string $userId): void;

    /**
     * Hard-deletes every session row of the user — active or not — and returns how many were removed. Unlike
     * the revocations (which flip a status but keep the row, with its `ip`/`device`), this drops the rows
     * outright: the GDPR-erasure path needs the subject's residual session PII gone, not merely marked
     * revoked. Idempotent — a second pass with a subject that has no rows deletes nothing and returns 0.
     */
    public function deleteAllForUser(string $userId): int;
}
