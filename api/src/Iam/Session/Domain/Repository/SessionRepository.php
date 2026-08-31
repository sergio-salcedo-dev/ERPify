<?php

declare(strict_types=1);

namespace Erpify\Iam\Session\Domain\Repository;

use DateTimeImmutable;
use Erpify\Iam\Session\Domain\Entity\Session;
use Erpify\Iam\Session\Domain\SessionId;
use UnexpectedValueException;

/**
 * Aggregate-lifecycle port for {@see Session} backed by the server-side registry.
 *
 * The temporal-validity predicate (`status = ACTIVE AND expires_at > now`) lives in the read queries, not in a
 * persisted state: {@see findActiveById()} and {@see findByUserId()} both apply it, so an expired session is
 * invisible to the gate and to "my sessions" without any sweeper writing a transition. The bulk revocations run
 * as directed UPDATEs (no aggregate hydration), which is why they live on the port rather than looping the
 * aggregate.
 *
 * EVERY method here converts a store-connection FAILURE into a domain {@see
 * \Erpify\Iam\Session\Domain\Exception\SessionStoreUnavailable} (→ 503) so the gate can fail closed rather than
 * letting a raw infrastructure error surface as a 500. Universal rather than per-method because a single
 * request reaches several of them — the erasure path admits through {@see findActiveById()} and then deletes
 * through {@see deleteAllForUser()} — and one outage answered with two statuses is worse than either.
 *
 * A store that ANSWERS, with something that is not an affected-row count, is a different event and keeps a
 * different status: it is not an outage, retrying changes nothing, and reporting 503 would invite a retry
 * that fails identically. Every method whose implementation runs a bulk statement therefore also declares
 * `\UnexpectedValueException` (→ 500) — the two `void` revocations included, because they run one too.
 */
interface SessionRepository
{
    /**
     * @throws \Erpify\Iam\Session\Domain\Exception\SessionStoreUnavailable when the store is unreachable
     */
    public function save(Session $session): void;

    /**
     * Returns the session only when it is admissible now — `status = ACTIVE AND expires_at > now`. A revoked,
     * expired or absent session resolves to `null`, which the gate maps to the 401 re-login outcome.
     *
     * @throws \Erpify\Iam\Session\Domain\Exception\SessionStoreUnavailable when the store is unreachable
     */
    public function findActiveById(SessionId $id): ?Session;

    /**
     * The user's currently-admissible sessions, newest first, for the "my sessions" projection (same temporal
     * predicate). The order is part of the contract rather than an adapter detail: the projection renders the
     * list in the order it arrives, so the sequence a user reads on their devices screen is decided here.
     * It is a TOTAL order — `created_at` is stored to the second, so two sessions minted within one second
     * tie and the session id settles them; without that, the list could reshuffle between two requests.
     *
     * @throws \Erpify\Iam\Session\Domain\Exception\SessionStoreUnavailable when the store is unreachable
     *
     * @return list<Session>
     */
    public function findByUserId(string $userId): array;

    /**
     * Bulk-revokes every currently-active session of the user EXCEPT `$currentSessionId` — the "close the
     * others" action, which never self-expels the session in hand.
     *
     * @throws \Erpify\Iam\Session\Domain\Exception\SessionStoreUnavailable when the store is unreachable
     * @throws UnexpectedValueException                                     when the store yields no affected-row count
     */
    public function revokeOthersForUser(string $userId, SessionId $currentSessionId): void;

    /**
     * Bulk-revokes every currently-active session of the user (the reset-everywhere capability).
     *
     * @throws \Erpify\Iam\Session\Domain\Exception\SessionStoreUnavailable when the store is unreachable
     * @throws UnexpectedValueException                                     when the store yields no affected-row count
     */
    public function revokeAllForUser(string $userId): void;

    /**
     * Hard-deletes every session row of the user — active or not — and returns how many were removed. Unlike
     * the revocations (which flip a status but keep the row, with its `ip`/`device`), this drops the rows
     * outright: the GDPR-erasure path needs the subject's residual session PII gone, not merely marked
     * revoked. Idempotent — a second pass with a subject that has no rows deletes nothing and returns 0.
     *
     * That zero is a fact about the store and never a stand-in for one: an implementation may not answer it
     * because it could not tell what the store returned. This method's callers read the number as erasure
     * evidence, so an implementation that cannot produce a count raises instead of reporting none.
     *
     * @throws \Erpify\Iam\Session\Domain\Exception\SessionStoreUnavailable when the store is unreachable
     * @throws UnexpectedValueException                                     when the store yields no affected-row count
     */
    public function deleteAllForUser(string $userId): int;

    /**
     * Hard-deletes the rows that have outlived the registry's retention floor: sessions revoked before
     * `$revokedBefore`, and sessions whose `expiresAt` fell before `$expiredBefore`.
     *
     * Two thresholds rather than one, because the two kinds of dead row are dated by different columns and
     * their windows answer different questions: a revoked row is dead from the instant it was revoked, while
     * an unrevoked one is only dead once its absolute expiry is far enough behind it. **A row goes when the
     * FIRST of its applicable windows elapses**, which is why only the revocation branch names a status:
     * revocation is a decision, so it applies to rows carrying that state, while expiry is a property of the
     * row's own clock and applies to every row. The alternative — judging a revoked row by `revokedAt` alone —
     * lets a revocation lengthen the life of a row that lapsed months earlier, since the bulk revocations
     * stamp `revokedAt = now` on rows already past their expiry.
     *
     * This is the routine retention floor and NOT the erasure path: a GDPR erasure calls
     * {@see deleteAllForUser()}, immediately and inside its own transaction. Idempotent — a second pass at the
     * same instant finds nothing left and returns 0.
     *
     * @throws \Erpify\Iam\Session\Domain\Exception\SessionStoreUnavailable when the store is unreachable
     * @throws UnexpectedValueException                                     when the store yields no affected-row count
     *
     * @return int the number of rows removed
     */
    public function deleteRetired(DateTimeImmutable $revokedBefore, DateTimeImmutable $expiredBefore): int;
}
