<?php

declare(strict_types=1);

namespace Erpify\Shared\Domain\Search;

use IteratorAggregate;

/**
 * Domain port for paginated search results.
 *
 * Yields entities via iteration and exposes pagination metadata: the current
 * page, an optional total page count (null in light pagination mode), a
 * "has more pages" hint, and the cursor positioning the next page.
 *
 * @template T of object
 *
 * @extends IteratorAggregate<int, T>
 */
interface PaginatedResult extends IteratorAggregate
{
    public function getCurrentPage(): int;

    public function getPageCount(): ?int;

    public function hasMorePages(): bool;

    public function getCursor(): SearchCursor;
}
