<?php

declare(strict_types=1);

namespace Erpify\Shared\Infrastructure\Persistence;

/**
 * Controls how paginated queries compute result metadata.
 *
 * Example: `GET /banks?paginationMode=light` skips the COUNT(*) and returns only
 * a "has more pages" hint, while `paginationMode=detailed` (default) returns
 * total record and page counts.
 */
enum PaginationMode: string
{
    /**
     * Runs an extra COUNT(*) query to expose total record and page counts.
     * Use when the UI needs to render full pagination (e.g. "Page 3 of 42").
     */
    case DETAILED = 'detailed';

    /**
     * Skips the COUNT(*) and instead fetches one extra row to detect whether
     * a next page exists. Cheaper on large tables; use for infinite scroll or
     * "Next/Previous" navigation where totals are not displayed.
     */
    case LIGHT = 'light';
}
