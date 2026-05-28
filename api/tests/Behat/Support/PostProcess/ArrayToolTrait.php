<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Support\PostProcess;

use Erpify\Tests\Behat\Support\Tool\ArrayTools;
use JsonException;

trait ArrayToolTrait
{
    /**
     * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
     *
     * @throws JsonException
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
                \json_encode($expected, JSON_THROW_ON_ERROR),
                PHP_EOL,
                PHP_EOL,
                \json_encode($actual, JSON_THROW_ON_ERROR),
            ),
        );
    }
}
