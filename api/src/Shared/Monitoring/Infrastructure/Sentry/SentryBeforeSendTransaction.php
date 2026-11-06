<?php

declare(strict_types=1);

namespace Erpify\Shared\Monitoring\Infrastructure\Sentry;

use Sentry\Event;

/**
 * `before_send_transaction`: a performance transaction is a second event stream, and `before_send` never sees
 * it. Its request URL, query string and trace data carry the query of every traced request — on the evidence
 * PUT, the caller's original filename — into a third-party sink with retention of its own, so the same URI
 * vocabulary is applied here as on the error path. Nothing is dropped: a transaction is not an error and the
 * filter's rules do not apply to it.
 */
final readonly class SentryBeforeSendTransaction
{
    public function __construct(
        private SentryEventScrubber $scrubber,
    ) {
    }

    public function __invoke(Event $event): Event
    {
        return $this->scrubber->scrubTransaction($event);
    }
}
