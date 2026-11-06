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
    /** Attributes whose value is a whole URI. */
    private const array URI_KEYS = ['http.url', 'url.full', 'url'];

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
        $path = isset($data['http.url']) && \is_string($data['http.url']) ? $data['http.url'] : null;

        foreach (self::URI_KEYS as $key) {
            if (isset($data[$key]) && \is_string($data[$key])) {
                $data[$key] = RequestUriRedaction::redact($data[$key]);
            }
        }

        foreach (self::QUERY_KEYS as $key) {
            if (isset($data[$key]) && \is_string($data[$key])) {
                $data[$key] = RequestUriRedaction::redactQuery($data[$key], $path);
            }
        }

        return $data;
    }
}
