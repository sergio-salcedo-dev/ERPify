<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Context;

use RuntimeException;

/**
 * Cross-context scenario memory for {alias} interpolation (multiple Behat contexts share one session).
 */
final class ScenarioRememberedValues
{
    /** @var array<string, string> */
    private static array $values = [];

    public static function reset(): void
    {
        self::$values = [];
    }

    public static function set(string $alias, string $value): void
    {
        self::$values[$alias] = $value;
    }

    /**
     * @throws RuntimeException
     */
    public static function require(string $alias): string
    {
        if ('' === $alias || !isset(self::$values[$alias]) || '' === self::$values[$alias]) {
            throw new RuntimeException(\sprintf('Unknown or empty remembered value for alias %s', $alias));
        }

        return self::$values[$alias];
    }

    public static function interpolate(string $text): string
    {
        foreach (self::$values as $key => $value) {
            $text = \str_replace('{' . $key . '}', $value, $text);
        }

        return $text;
    }
}
