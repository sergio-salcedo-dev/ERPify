<?php

declare(strict_types=1);

namespace Erpify\Shared\Infrastructure\Persistence;

enum SortDirection: string
{
    case ASC = 'ASC';
    case DESC = 'DESC';
}
