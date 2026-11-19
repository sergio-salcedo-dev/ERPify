<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application\Listing;

use Erpify\Documents\Document\Domain\Projection\DocumentVersionRow;

/**
 * One page of a version series in creation order. `nextAfterId` is the last version of the page when another version
 * exists beyond it, and null on the last page.
 */
final readonly class DocumentSeriesPage
{
    /**
     * @param list<DocumentVersionRow> $versions
     */
    public function __construct(
        public string $seriesId,
        public array $versions,
        public ?string $nextAfterId,
    ) {
    }
}
