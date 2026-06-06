<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Domain\Search\Mother;

use Erpify\Shared\Domain\Search\Filter;
use Erpify\Shared\Domain\Search\Filters;

final class FiltersMother
{
    public static function with(Filter ...$filters): Filters
    {
        return new Filters(...$filters);
    }

    public static function typical(): Filters
    {
        return new Filters(FilterMother::eq(), FilterMother::in(), FilterMother::contains());
    }
}
