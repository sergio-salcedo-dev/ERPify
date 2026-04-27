<?php

declare(strict_types=1);

namespace Erpify\Shared\Infrastructure\Persistence;

enum PaginatorOption: string
{
    /** Whether the query joins a collection (true by default). */
    case FETCH_JOIN_COLLECTION = 'fetchJoinCollection';

    /** Disable cursor optimization. Useful for queries with non-mappable order-by. */
    case ENABLE_CURSOR_PAGINATION = 'enableCursorPagination';

    /** Pagination mode (detailed = compute count; light = skip count). */
    case PAGINATION_MODE = 'paginationMode';
}
