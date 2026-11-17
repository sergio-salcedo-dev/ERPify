<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application\Listing;

use Erpify\Documents\Document\Domain\DocumentState;
use Erpify\Documents\Document\Domain\Projection\DocumentListRow;

/**
 * What {@see DocumentNameOpener} needs to open one document's name: the row's id, its scope and the sealed filename.
 * A NULL ciphertext is a document with no name to open (erased, or filed without one) and costs no keystore lookup.
 */
final readonly class NameSource
{
    public function __construct(
        public string $documentId,
        public string $scopeId,
        public ?string $filenameCiphertext,
    ) {
    }

    /**
     * The source of a listed row. An `Erased` document is handed over with no ciphertext whatever the column holds:
     * its name is gone by decision, and a lookup for it would be a keystore query for a scope that no longer answers.
     */
    public static function fromRow(DocumentListRow $row): self
    {
        return new self(
            $row->id,
            $row->scopeId,
            DocumentState::Erased === $row->state ? null : $row->filenameCiphertext,
        );
    }
}
