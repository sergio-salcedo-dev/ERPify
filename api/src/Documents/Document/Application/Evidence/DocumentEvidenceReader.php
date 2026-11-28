<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application\Evidence;

use Erpify\Documents\Document\Application\Listing\DocumentNameOpener;
use Erpify\Documents\Document\Application\Listing\NameSource;
use Erpify\Documents\Document\Application\Listing\OpenedName;
use Erpify\Documents\Document\Application\Read\ErasureLedgerOverride;
use Erpify\Documents\Document\Domain\Exception\DocumentGone;
use Erpify\Documents\Document\Domain\Projection\DocumentListRow;
use Erpify\Documents\Document\Domain\Repository\DocumentListingRepository;
use Erpify\Shared\Uuid\Domain\Uuid;
use Override;

/**
 * {@see EvidenceDocuments} over the snapshot's read side: no lock, no aggregate, and the names of a set through
 * {@see DocumentNameOpener} — one keystore query per call, degrading per document.
 */
final readonly class DocumentEvidenceReader implements EvidenceDocuments
{
    public function __construct(
        private DocumentListingRepository $documents,
        private DocumentNameOpener $nameOpener,
        private ErasureLedgerOverride $ledgerOverride,
    ) {
    }

    #[Override]
    public function filingOf(string $documentId): ?EvidenceFiling
    {
        Uuid::ensure($documentId);

        $row = $this->documents->find($documentId);

        return $row instanceof DocumentListRow ? $this->filingFrom($row) : null;
    }

    #[Override]
    public function filingsOf(array $documentIds): array
    {
        $filings = [];

        foreach ($this->documents->findMany($documentIds) as $row) {
            $filings[$row->id] = $this->filingFrom($row);
        }

        return $filings;
    }

    #[Override]
    public function describe(array $documentIds): array
    {
        if ($this->ledgerOverride->isActive()) {
            throw DocumentGone::create();
        }

        $rows = $this->documents->findMany($documentIds);
        $names = $this->nameOpener->open(\array_map(NameSource::fromRow(...), $rows));
        $described = [];

        foreach ($rows as $row) {
            $described[$row->id] = new EvidenceDocument(
                $row->id,
                ($names[$row->id] ?? OpenedName::absent())->name,
                $row->state->value,
                $row->digest,
                $row->sizeBytes,
                $row->createdAt,
            );
        }

        return $described;
    }

    private function filingFrom(DocumentListRow $row): EvidenceFiling
    {
        return new EvidenceFiling(
            $row->id,
            $row->state->value,
            $row->retentionReason,
            $row->legalBasis,
            $row->reviewAt,
        );
    }
}
