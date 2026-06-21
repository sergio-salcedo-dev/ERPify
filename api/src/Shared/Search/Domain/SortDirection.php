<?php

declare(strict_types=1);

namespace Erpify\Shared\Search\Domain;

/**
 * Sort direction carried in {@see SearchCriteria}. A pure enum with no dependencies, so it is
 * valid in Domain: the search contract (Domain) owns it, and infrastructure adapters consume
 * its backing value. The wire tokens are uppercase (`ASC`/`DESC`), distinct from the lowercase
 * filter operators.
 */
enum SortDirection: string
{
    case ASC = 'ASC';
    case DESC = 'DESC';
}
