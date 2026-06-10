<?php

declare(strict_types=1);

namespace Erpify\Shared\Monitoring\Infrastructure\Sentry;

use Erpify\Shared\Domain\Exception\ClientError;
use Sentry\Event;
use Sentry\EventHint;

/**
 * Sentry `before_send` filter: drops events whose originating throwable is a {@see ClientError}
 * — an expected 4xx business outcome / invalid client request that needs no technical
 * intervention (e.g. deleting a bank that still has accounts → 409). Returning `null` stops the
 * event from leaving the process, keeping error tracking signal-rich.
 *
 * 5xx paths are untouched: a marker-less DomainException maps to `unhandled-exception` (500), and
 * any non-domain throwable is not a ClientError — both keep flowing to Sentry. The decision is
 * purely type-based and reuses the RFC 9457 marker hierarchy as the single source of truth (see
 * {@see ClientError}); there is no per-exception denylist to maintain.
 *
 * Kept separate from {@see SentryEventScrubber} (which redacts PII from events that ARE sent):
 * "should this event be sent?" and "scrub what we send" are distinct concerns, composed by
 * {@see SentryBeforeSend}.
 */
final class SentryEventFilter
{
    public function __invoke(Event $event, ?EventHint $hint): ?Event
    {
        if ($hint?->exception instanceof ClientError) {
            return null;
        }

        return $event;
    }
}
