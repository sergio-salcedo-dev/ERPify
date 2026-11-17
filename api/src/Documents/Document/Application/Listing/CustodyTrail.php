<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application\Listing;

use Erpify\Documents\Document\Domain\Projection\CustodyTrailRow;

/**
 * One page of a document's custody trail: its rows in `seq` order and the cursor of the next page, NULL on the last.
 * `$document` is the metadata with the opened name, and it is set on the FIRST page only, so a long walk opens the
 * name once.
 */
final readonly class CustodyTrail
{
    /**
     * @param list<CustodyTrailRow> $entries
     */
    public function __construct(
        public ?ListedDocument $document,
        public array $entries,
        public ?int $nextAfterSeq,
    ) {
    }
}
