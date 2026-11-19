<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application\Erasure;

use Erpify\Documents\Document\Domain\Erasure\ErasureSystemReason;
use Erpify\Documents\Document\Domain\Repository\DocumentRepository;
use Erpify\Documents\Document\Domain\Storage\DocumentStorage;
use Erpify\Documents\Document\Domain\Storage\StorageArea;
use Erpify\Shared\Uuid\Domain\Uuid;

/**
 * Finishes the erasure of a scope the ledger names, with no operator and no ledger write: the entry is already there,
 * and the ledger holds scope ids only, so the actor is NULL (the system) and the reason says which of the two callers
 * ran it. There is no voter by construction, because this runs under the restore procedure or the completion sweep and
 * never behind an HTTP route.
 *
 * **It is never a parameter of {@see EraseDocument}.** A flag that skipped the ledger write on the controller's use
 * case would be one argument away from an erasure with no record and no authorisation; this is a separate class and
 * `ReplayErasureCallersGateTest` holds the classes that construct with it to the restore command and the completion
 * handler.
 *
 * What it does for a scope the restored database never held (no row): the key is tombstoned, so a writer that arrives
 * later cannot mint one, and — for a restore replay only — whatever object lies under the scope is deleted, because the
 * ledger is the authority for it. The completion sweep deletes nothing in that case: a live system with an entry and no
 * row is not a thing it should clean by guess.
 *
 * For a scope with a row, a restore replay also empties the row's object marker inside the erasure transaction, so the
 * deletion sweep takes the object up again: the objects may have come back from a backup the marker knows nothing
 * about, and the replay visits every scope of the ledger, not only the unfinished ones, for exactly that reason.
 */
final readonly class ReplayErasure
{
    public function __construct(
        private ApplyErasure $applyErasure,
        private DocumentRepository $documents,
        private DocumentStorage $storage,
    ) {
    }

    public function replay(string $scopeId, ErasureSystemReason $reason): void
    {
        Uuid::ensure($scopeId);

        $scopeId = \strtolower($scopeId);
        $documentId = $this->documents->findIdByScope($scopeId);

        if (null === $documentId) {
            $this->applyErasure->tombstoneScopeWithoutRow($scopeId);

            if (ErasureSystemReason::LedgerReplay === $reason) {
                $this->storage->delete(StorageArea::Staging, $scopeId);
                $this->storage->delete(StorageArea::Final, $scopeId);
            }

            return;
        }

        $this->applyErasure->apply($documentId, null, $reason->value, ErasureSystemReason::LedgerReplay === $reason);
    }
}
