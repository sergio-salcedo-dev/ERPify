<?php

declare(strict_types=1);

namespace Erpify\Shared\Monitoring\Infrastructure\Sentry;

use Erpify\Shared\ErrorContract\Application\RequestUriRedaction;

/**
 * The URI vocabulary applied to a span's or a trace's data: the attributes whose value is a whole URI or a query
 * alone, under both the legacy and the current semantic-convention names. A transaction carries the request URI
 * there, outside the `request` block the error path scrubs.
 */
final class SpanUriRedaction
{
    /** Attributes whose value is a whole URI, or a path with its query (`http.target`). */
    private const array URI_KEYS = ['http.url', 'url.full', 'url', 'http.target'];

    /** Where a span names its request path, in the order the SDK versions have spelled it. */
    private const array PATH_KEYS = ['http.url', 'url.full', 'url', 'http.target', 'url.path'];

    /** Attributes whose value is a query string alone. */
    private const array QUERY_KEYS = ['http.query', 'url.query'];

    private function __construct()
    {
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    public static function apply(array $data): array
    {
        $path = self::uploadRoutePathIn($data);

        foreach (self::URI_KEYS as $key) {
            if (isset($data[$key]) && \is_string($data[$key])) {
                $data[$key] = null === $path
                    ? RequestUriRedaction::redact($data[$key])
                    : self::withoutQuery($data[$key]);
            }
        }

        foreach (self::QUERY_KEYS as $key) {
            if (isset($data[$key]) && \is_string($data[$key])) {
                $data[$key] = RequestUriRedaction::redactQuery($data[$key], $path);
            }
        }

        return $data;
    }

    /**
     * The URI with its whole query withheld, whatever its own path says: once any key of the span names the upload
     * route, every spelling of its URI is that request's. A fragment goes with the query.
     */
    private static function withoutQuery(string $uri): string
    {
        $separator = \strpos($uri, '?');

        if (false === $separator) {
            $fragment = \strpos($uri, '#');

            return false === $fragment ? $uri : \substr($uri, 0, $fragment);
        }

        return \substr($uri, 0, $separator) . '?' . RequestUriRedaction::SENTINEL;
    }

    /**
     * The first path key naming the upload route, or null: any spelling of it withholds the whole query, and the
     * keys need not agree.
     *
     * @param array<array-key, mixed> $data
     */
    private static function uploadRoutePathIn(array $data): ?string
    {
        foreach (self::PATH_KEYS as $key) {
            $value = $data[$key] ?? null;

            if (\is_string($value) && RequestUriRedaction::withholdsWholeQuery($value)) {
                return $value;
            }
        }

        return null;
    }
}
