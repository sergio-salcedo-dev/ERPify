<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application\Evidence;

use Erpify\Documents\Document\Application\Listing\DocumentNameOpener;
use Erpify\Documents\Document\Application\Listing\NameSource;
use Erpify\Documents\Document\Application\Listing\OpenedName;
use Erpify\Documents\Document\Application\Read\ErasureLedgerOverride;
use Erpify\Documents\Document\Domain\DocumentState;
use Erpify\Documents\Document\Domain\Exception\DocumentGone;
use Erpify\Documents\Document\Domain\Projection\DocumentListRow;
use Erpify\Documents\Document\Domain\Repository\DocumentListingRepository;

/**
 * The seam Documents publishes to a context that references documents by id as evidence (the first is
 * `Backoffice/BankAccount`): the metadata of a set of documents with their names opened, at a constant cost — one
 * statement for the rows and ONE keystore query for the names ({@see DocumentNameOpener}), whatever the count.
 *
 * It answers the same 410 the Documents listing answers while the erasure ledger's override is set
 * ({@see ErasureLedgerOverride}): after a restore without the ledger, an erased document can be back as a live row
 * with a key that opens, so neither its state nor its name can be trusted.
 *
 * Writes no audit row: describing evidence is an ordinary read, like the listing (the audit boundary is the byte
 * read, `ReadDocument`).
 */
final readonly class DescribeEvidenceDocuments
{
    public function __construct(
        private DocumentListingRepository $documents,
        private DocumentNameOpener $nameOpener,
        private ErasureLedgerOverride $ledgerOverride,
    ) {
    }

    /**
     * @param list<string> $documentIds well-formed UUIDs
     *
     * @throws DocumentGone when the erasure ledger's override is set (410)
     *
     * @return array<string, EvidenceDocument> keyed by the lower-case document id; an unknown id has no entry
     */
    public function describe(array $documentIds): array
    {
        if ($this->ledgerOverride->isActive()) {
            throw DocumentGone::create();
        }

        $rows = $this->documents->findMany($documentIds);
        $names = $this->nameOpener->open(\array_map(NameSource::fromRow(...), $rows));

        $described = [];

        foreach ($rows as $row) {
            $described[\strtolower($row->id)] = $this->describeRow($row, $names[$row->id] ?? OpenedName::absent());
        }

        return $described;
    }

    private function describeRow(DocumentListRow $row, OpenedName $name): EvidenceDocument
    {
        return new EvidenceDocument(
            $row->id,
            $row->state->value,
            $name->name,
            $row->sizeBytes,
            $row->retentionReason,
            $row->legalBasis,
            $row->reviewAt,
            $row->createdAt,
            DocumentState::Erased === $row->state,
        );
    }
}
