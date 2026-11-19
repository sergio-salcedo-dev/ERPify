<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application\Erasure;

/**
 * What the database says about the scopes the ledger names, for the completion sweep, the replay and the override's
 * clearing. Reads only.
 */
interface ErasureProgress
{
    /**
     * The scopes whose erasure is not finished: a key not yet tombstoned, or a document row that is not `Erased`.
     * A scope with no row and a tombstone is finished (the upload never became a document, or the ledger entry
     * names a document this database never held).
     *
     * @param list<string> $scopeIds
     *
     * @return list<string>
     */
    public function unfinished(array $scopeIds): array;

    /**
     * How many rows are NOT `Erased` although their scope is named: what the Document-driven reconciler would alarm
     * on, restricted to the ledger. The override clears only when this is zero after the replay.
     *
     * @param list<string> $scopeIds
     */
    public function notErasedAmong(array $scopeIds): int;
}
