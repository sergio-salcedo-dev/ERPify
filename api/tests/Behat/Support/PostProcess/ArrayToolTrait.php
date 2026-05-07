<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Support\PostProcess;

use Erpify\Tests\Behat\Support\Tool\ArrayTools;

trait ArrayToolTrait
{
    /**
     * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
     */
    public function arrayAreTheSame(mixed $expected, mixed $actual, bool $sort = false): void
    {
        if ($sort) {
            ArrayTools::fullSort($expected);
            ArrayTools::fullSort($actual);
        }

        self::assertEquals(
            $expected,
            $actual,
            \sprintf(
                'The expected %s %s %s is not the same as %s %s',
                PHP_EOL,
                \json_encode($expected),
                PHP_EOL,
                PHP_EOL,
                \json_encode($actual),
            ),
        );
    }
}
