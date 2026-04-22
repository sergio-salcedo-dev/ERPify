<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Support\Tool;

class ArrayTools
{
    public static function fullSort(mixed &$array): void
    {
        if (!\is_array($array)) {
            return;
        }

        \array_multisort($array);
        \ksort($array);

        foreach ($array as &$value) {
            if (\is_array($value)) {
                \array_multisort($value);
                \ksort($value);
                self::fullSort($value);
            }
        }
    }
}
