<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate\Fixture\StackedDocblocks;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;

final class NestedAttributeBetweenFixture
{
    /**
     * Inert, and the brackets nested inside the attributes below are what a naive scan ends the group on.
     */
    #[TestWith([[1, 2], 'x'])]
    #[DataProvider('cases')]
    /**
     * The block the declaration actually carries.
     */
    public function value(int $first, int $second, string $label): int
    {
        return $first + $second + \strlen($label);
    }

    /**
     * @return iterable<array{int, int, string}>
     */
    public static function cases(): iterable
    {
        yield [1, 2, 'x'];
    }
}
