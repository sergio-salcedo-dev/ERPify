<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application\Resource;

/**
 * Wire contract of a series page: its versions in creation order and the cursor of the next page, null on the last.
 */
final readonly class DocumentSeriesResource
{
    /**
     * @param list<DocumentVersionResource> $versions
     */
    public function __construct(
        public string $seriesId,
        public array $versions,
        public ?string $nextAfterId,
    ) {
    }
}
