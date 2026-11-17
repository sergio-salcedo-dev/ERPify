<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application\Listing;

use Erpify\Documents\Document\Domain\Projection\DocumentListRow;

/**
 * A document's metadata with its opened name, the carrier from the listing use cases to the resource mapper: the row
 * as the read side returned it plus the name, which is NULL whenever there is none to give. Never serialised.
 */
final readonly class ListedDocument
{
    public function __construct(
        public DocumentListRow $row,
        public ?string $name,
    ) {
    }
}
