<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

use UnexpectedValueException;

/**
 * Reads one value out of nested data a test does not own the shape of (a database row, a decoded response, a
 * parsed YAML file), failing loudly when the path or the type is not what the test expects instead of letting a
 * missing key read as null.
 */
final class Dig
{
    private function __construct()
    {
    }

    public static function value(mixed $data, int|string ...$path): mixed
    {
        foreach ($path as $key) {
            if (!\is_array($data) || !\array_key_exists($key, $data)) {
                throw new UnexpectedValueException(\sprintf('No value at "%s".', \implode('.', $path)));
            }

            $data = $data[$key];
        }

        return $data;
    }

    public static function string(mixed $data, int|string ...$path): string
    {
        $value = self::value($data, ...$path);

        if (!\is_string($value)) {
            throw new UnexpectedValueException(\sprintf('The value at "%s" is not a string.', \implode('.', $path)));
        }

        return $value;
    }

    public static function int(mixed $data, int|string ...$path): int
    {
        $value = self::value($data, ...$path);

        if (!\is_numeric($value)) {
            throw new UnexpectedValueException(\sprintf('The value at "%s" is not a number.', \implode('.', $path)));
        }

        return (int) $value;
    }

    /**
     * @return array<array-key, mixed>
     */
    public static function array(mixed $data, int|string ...$path): array
    {
        $value = self::value($data, ...$path);

        if (!\is_array($value)) {
            throw new UnexpectedValueException(\sprintf('The value at "%s" is not an array.', \implode('.', $path)));
        }

        return $value;
    }
}
