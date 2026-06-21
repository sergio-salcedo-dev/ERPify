<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Search\Domain\Mother;

use Erpify\Shared\Search\Domain\Filter;
use Erpify\Shared\Search\Domain\Filters;

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
