<?php

declare(strict_types=1);

namespace Erpify\Shared\Infrastructure\Persistence;

enum QueryParam: string
{
    case ID = 'id';
    case CREATED_AT = 'createdAt';
    case UPDATED_AT = 'updatedAt';
    case PAGE = 'page';
    case CURSOR = 'cursor';
    case PAGINATION_MODE = 'paginationMode';
    case SORT = 'sort';
    case DIRECTION = 'direction';
    case LIMIT = 'limit';
}
