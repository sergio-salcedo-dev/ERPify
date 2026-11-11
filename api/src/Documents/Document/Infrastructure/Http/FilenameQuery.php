<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Http;

/**
 * Reads the `filename` parameter out of the RAW query string, because the parsed one cannot answer the question
 * asked: PHP keeps the last of two `filename=` pairs and silently turns `filename[]=` into an array, so a repeat
 * would pass as a single value. Every pair whose decoded name is `filename`, or `filename` followed by a `[`, is
 * counted before its value is decoded; the use case refuses anything but exactly one.
 *
 * Values are decoded the way PHP decodes a query (`+` is a space), which is why the client encodes with
 * `encodeURIComponent`. The value is never logged or echoed from here.
 */
final class FilenameQuery
{
    private const string NAME = 'filename';

    private function __construct()
    {
    }

    /**
     * @return list<string>
     */
    public static function valuesIn(string $rawQuery): array
    {
        $values = [];

        foreach ('' === $rawQuery ? [] : \explode('&', $rawQuery) as $pair) {
            $equals = \strpos($pair, '=');
            $name = \urldecode(false === $equals ? $pair : \substr($pair, 0, $equals));

            if (self::NAME !== $name && !\str_starts_with($name, self::NAME . '[')) {
                continue;
            }

            $values[] = false === $equals ? '' : \urldecode(\substr($pair, $equals + 1));
        }

        return $values;
    }
}
