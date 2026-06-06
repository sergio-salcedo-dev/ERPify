<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Domain\Search\Mother;

use Erpify\Shared\Domain\Search\Filter;

final class FilterMother
{
    public static function eq(string $field = 'name', string $value = 'BBVA'): Filter
    {
        return Filter::eq($field, $value);
    }

    /**
     * @param list<string> $values
     */
    public static function in(string $field = 'name', array $values = ['BBVA', 'Caixa']): Filter
    {
        return Filter::in($field, $values);
    }

    public static function contains(string $field = 'name', string $value = 'banc'): Filter
    {
        return Filter::contains($field, $value);
    }
}
