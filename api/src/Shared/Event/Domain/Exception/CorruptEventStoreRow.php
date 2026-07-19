<?php

declare(strict_types=1);

namespace Erpify\Shared\Event\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;

/**
 * An `event_store` row holds a value that breaks its column's storage contract — e.g. a non-numeric
 * `sequence` / `aggregate_version` where the schema guarantees an integer. Marker-less on purpose:
 * corrupt log data is an actionable server fault that must reach Sentry as a 500, never a client 4xx.
 */
final class CorruptEventStoreRow extends DomainException
{
    public static function nonNumericColumn(string $column): self
    {
        return new self(
            type: 'corrupt-event-store-row',
            title: \sprintf('The event_store column "%s" holds a non-numeric value.', $column),
            context: ['column' => $column],
        );
    }

    public static function malformedPayloadMember(string $eventName, string $member, string $expected): self
    {
        return new self(
            type: 'corrupt-event-store-row',
            title: \sprintf(
                'The stored "%s" payload member "%s" is not %s.',
                $eventName,
                $member,
                $expected,
            ),
            context: ['event_name' => $eventName, 'member' => $member, 'expected' => $expected],
        );
    }
}
