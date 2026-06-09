<?php

declare(strict_types=1);

namespace Erpify\Shared\Domain\Search;

use IteratorAggregate;

/**
 * Domain port for paginated search results.
 *
 * Yields entities via iteration and exposes pagination metadata: the current
 * page, an optional total page count and total item count (both null in light
 * pagination mode), a "has more pages" hint, and the cursor positioning the
 * next page.
 *
 * @template-covariant T of object
 *
 * @extends IteratorAggregate<int, T>
 */
interface PaginatedResult extends IteratorAggregate
{
    public function getCurrentPage(): int;

    public function getPageCount(): ?int;

    /**
     * Total number of items matching the query across all pages — null in light
     * pagination mode, where no COUNT is run. Sourced from the cursor; exposed
     * here so callers read pagination metadata from one object (Tell, Don't Ask)
     * instead of reaching through {@see getCursor()}.
     */
    public function getCount(): ?int;

    public function hasMorePages(): bool;

    public function getCursor(): SearchCursor;
}
