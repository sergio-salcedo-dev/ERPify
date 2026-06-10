<?php

declare(strict_types=1);

namespace Erpify\Shared\Monitoring\Infrastructure\Sentry;

use Sentry\Event;
use Sentry\EventHint;

/**
 * Sentry `before_send` composition root (wired in `config/packages/sentry.yaml`). Runs the
 * {@see SentryEventFilter} first: if it drops the event (an expected 4xx client error) nothing is
 * sent and no scrubbing is needed; otherwise the kept event is handed to
 * {@see SentryEventScrubber} for defense-in-depth PII redaction before transmission.
 *
 * @api Consumed by the SentryBundle via the `before_send` service reference in sentry.yaml,
 *      which static analysis cannot trace — an entry point, not dead code.
 */
final readonly class SentryBeforeSend
{
    public function __construct(
        private SentryEventFilter $filter,
        private SentryEventScrubber $scrubber,
    ) {
    }

    public function __invoke(Event $event, ?EventHint $hint): ?Event
    {
        $kept = ($this->filter)($event, $hint);

        if (!$kept instanceof Event) {
            return null;
        }

        return ($this->scrubber)($kept);
    }
}
