<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Domain\Repository;

use DateTimeImmutable;
use Erpify\Documents\Document\Domain\Entity\UploadIntent;

/**
 * Port for the upload-intent record ({@see UploadIntent}). Every method that writes or locks assumes the
 * caller owns the transaction; none opens one.
 *
 * Time is the database's: {@see now()} is what every judgement of a TTL, a lease or a deadline compares
 * against, read inside the transaction that holds the row.
 */
interface UploadIntentRepository
{
    /**
     * The database clock (`NOW()`): the transaction's start time inside a transaction.
     */
    public function now(): DateTimeImmutable;

    /**
     * Inserts the intent unless another one already holds its key hash, and says whether it did. An intent with
     * no key hash always inserts. Never raises a unique violation for the key: a violation would close the
     * entity manager, so the conflict is resolved by the statement itself and the winner is read separately.
     */
    public function insertUnlessKeyTaken(UploadIntent $intent): bool;

    public function findByKeyHash(string $keyHash): ?UploadIntent;

    /**
     * Loads the intent under `SELECT … FOR UPDATE`, re-hydrated from the locked row; null when there is none.
     */
    public function lockForUpdate(string $id): ?UploadIntent;

    /** Writes the changes made to an intent loaded through {@see lockForUpdate()}. */
    public function save(UploadIntent $intent): void;

    public function delete(UploadIntent $intent): void;

    /**
     * Serialises the claims of one actor, then all claims, for the rest of the transaction: transaction-scoped
     * advisory locks, per actor first, taken after the intent row and with nothing else acquired while held.
     */
    public function lockClaimSlots(string $actorId): void;

    /** Serialises the declares of one actor for the rest of the transaction, so its live-intent cap holds. */
    public function lockDeclareSlot(string $actorId): void;

    /** `Streaming` intents whose lease and deadline are both live, for one actor. */
    public function countLiveStreamingFor(string $actorId, DateTimeImmutable $now): int;

    /** `Streaming` intents whose lease and deadline are both live, across every actor. */
    public function countLiveStreaming(DateTimeImmutable $now): int;

    /** `Declared` intents whose TTL has not passed, for one actor. */
    public function countLiveDeclaredFor(string $actorId, DateTimeImmutable $now): int;

    /**
     * Ids of intents the reaper may act on — `Declared` past its TTL, `Streaming` past its lease or deadline,
     * `Expired` — in ascending id, never a `Committed` one. A candidate list only: the reaper rechecks each under
     * its lock.
     *
     * @return list<string>
     */
    public function reapCandidates(int $limit): array;

    /**
     * Deletes up to `$limit` `Committed` intents committed before `$cutoff` and returns how many it deleted.
     */
    public function deleteCommittedBefore(DateTimeImmutable $cutoff, int $limit): int;

    /** Whether an intent in `Declared` or `Streaming` holds the scope. */
    public function hasLiveIntentForScope(string $scopeId): bool;

    /**
     * Rewrites every intent of the subject: the actor to the erased-person marker and the key hash to NULL in
     * every state, and the `Declared` and `Streaming` ones to `Expired` with their lease set to now. Deletes
     * nothing, so the reaper still tombstones a scope that was minted. One statement over the subject's rows in
     * ascending id, each locked first, in the caller's transaction; returns how many rows it rewrote.
     */
    public function anonymiseActor(string $subjectId): int;
}
