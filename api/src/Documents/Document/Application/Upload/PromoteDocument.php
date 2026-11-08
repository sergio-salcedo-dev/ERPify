<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application\Upload;

use Erpify\Documents\Document\Domain\Repository\DocumentRepository;
use Erpify\Documents\Document\Domain\Storage\DocumentStorage;
use Erpify\Documents\Document\Domain\Storage\ObjectAlreadyExists;
use Erpify\Documents\Document\Domain\Storage\ObjectNotFound;
use Erpify\Documents\Document\Domain\Storage\StorageArea;

/**
 * Moves a committed document's sealed object from staging into the final namespace, marks the row promoted and
 * only then wakes the scan. The writer runs it right after its commit; the roll-forward sweep runs it for a row
 * whose writer died in between. Each step is safe to repeat, so a crash at any point is finished by the next run:
 *
 *  - the move accepts a final object that is already there (a previous run linked it and died before removing
 *    the staged name) and never overwrites it — the stale staged name is removed instead;
 *  - a staged object that is gone with the final one present is a move that already happened;
 *  - `promoted_at` is a compare-and-set, and only the call that set it dispatches the wake-up, so a crash after
 *    it loses at most a hint the scan sweep does not need.
 *
 * A row with no object in either namespace is not repaired here: it raises {@see ObjectNotFound} and the
 * scheduled report alarms on it.
 */
final readonly class PromoteDocument
{
    public function __construct(
        private DocumentStorage $storage,
        private DocumentRepository $documents,
        private DocumentScanWakeUp $scanWakeUp,
    ) {
    }

    /**
     * @throws ObjectNotFound when neither namespace holds the object
     */
    public function promote(string $documentId, string $scopeId): void
    {
        $this->moveToFinal($scopeId);

        if ($this->documents->markPromoted($documentId)) {
            $this->scanWakeUp->wake($documentId);
        }
    }

    /**
     * The idempotent half of the promotion, for a caller that sets `promoted_at` itself under its own lock.
     *
     * @throws ObjectNotFound when neither namespace holds the object
     */
    public function moveToFinal(string $scopeId): void
    {
        try {
            $this->storage->promote($scopeId);
        } catch (ObjectAlreadyExists) {
            // Linked by an earlier run that died before removing the staged name: the final object stands, and its
            // name is made durable before the staged one — the only other copy — goes.
            $this->storage->syncFinal($scopeId);
            $this->storage->delete(StorageArea::Staging, $scopeId);
        } catch (ObjectNotFound $notStaged) {
            if (!$this->storage->has(StorageArea::Final, $scopeId)) {
                throw $notStaged;
            }

            // Moved by an earlier run that died before syncing the final directory.
            $this->storage->syncFinal($scopeId);
        }
    }
}
