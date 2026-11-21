<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application\Versioning;

use Erpify\Documents\Document\Domain\DocumentState;
use Erpify\Documents\Document\Domain\Entity\Document;
use Erpify\Documents\Document\Domain\Exception\DocumentNotFound;
use Erpify\Documents\Document\Domain\Exception\PredecessorNotReady;
use Erpify\Documents\Document\Domain\Exception\VersionSlotTaken;
use Erpify\Documents\Document\Domain\Repository\DocumentRepository;
use Erpify\Documents\Document\Domain\Versioning\DocumentVersionSlots;

/**
 * The one way a version takes its predecessor's slot (`docs/adr/documents-custody-log.md` D12), shared by the two
 * transitions that make a version live: the new-version commit and the reopen of a failed version. Inside the caller's
 * transaction it takes the series row, then the predecessor row under `FOR UPDATE`, rechecks the predecessor, and only
 * then asks the lock-free recursive read whether a live version already holds the slot.
 *
 * An absent predecessor, and one in a series a series erasure has closed, answers like one that cannot be succeeded,
 * so neither caller can be used to learn whether an id exists. Every method assumes the caller owns the transaction.
 */
final readonly class TakeVersionSlot
{
    public function __construct(
        private DocumentRepository $documents,
        private DocumentVersionSlots $slots,
    ) {
    }

    /**
     * For a new version: the predecessor must be `Ready`. Answers it as loaded under its lock, so the caller copies the
     * series from the database. The series id is read first without a lock — it never changes — to know which row to
     * lock.
     *
     * @throws PredecessorNotReady when the predecessor is absent or not `Ready`, or its series is closed
     * @throws VersionSlotTaken    when a live version already succeeds it
     */
    public function forNewVersion(string $predecessorId): Document
    {
        $seriesId = $this->documents->findById($predecessorId)?->seriesId() ?? throw PredecessorNotReady::create();
        $predecessor = $this->lockUnderSeries($seriesId, $predecessorId);

        if (DocumentState::Ready !== $predecessor->state()) {
            throw PredecessorNotReady::create();
        }

        $this->ensureFree($predecessorId);

        return $predecessor;
    }

    /**
     * For a reopen of a failed version: the predecessor must not be `Erased` — while the failed version exists its
     * predecessor was `Ready` once, and `Erased` is the only state it can have left for.
     *
     * @throws PredecessorNotReady when the predecessor is `Erased` (or absent), or the series is closed
     * @throws VersionSlotTaken    when a live version already holds the slot
     */
    public function forReopen(string $seriesId, string $predecessorId): void
    {
        if (DocumentState::Erased === $this->lockUnderSeries($seriesId, $predecessorId)->state()) {
            throw PredecessorNotReady::create();
        }

        $this->ensureFree($predecessorId);
    }

    /**
     * @throws PredecessorNotReady when the series is closed by a series erasure, or the predecessor row is absent
     */
    private function lockUnderSeries(string $seriesId, string $predecessorId): Document
    {
        if (!$this->slots->lockSeries($seriesId)) {
            throw PredecessorNotReady::create();
        }

        try {
            return $this->documents->lockForUpdate($predecessorId);
        } catch (DocumentNotFound) {
            throw PredecessorNotReady::create();
        }
    }

    /**
     * @throws VersionSlotTaken when a live version is anywhere below the predecessor
     */
    private function ensureFree(string $predecessorId): void
    {
        if ($this->slots->isSlotHeld($predecessorId)) {
            throw VersionSlotTaken::create();
        }
    }
}
