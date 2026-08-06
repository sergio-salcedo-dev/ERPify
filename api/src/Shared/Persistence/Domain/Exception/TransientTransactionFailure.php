<?php

declare(strict_types=1);

namespace Erpify\Shared\Persistence\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;
use Erpify\Shared\ErrorContract\Domain\Exception\ServiceUnavailable;
use Throwable;

/**
 * The transaction was rolled back by the database for a reason that retrying may resolve — a deadlock
 * (`40P01`) or a serialization failure (`40001`). Nothing about the request was wrong, and nothing about it
 * needs to change: the identical bytes sent again are expected to succeed.
 *
 * **Why {@see ServiceUnavailable} (503) and not {@see \Erpify\Shared\ErrorContract\Domain\Exception\Conflict}
 * (409).** A 409 tells the caller to go resolve a conflict, which here is advice it cannot act on — there is
 * no conflicting state to reconcile, only a lock order two transactions took in opposite directions. 503 is
 * the code whose meaning is "try again shortly", and this marker also carries the Sentry decision that fits:
 * it does not extend `ClientError`, so an operational fault stays visible instead of being filtered away as
 * an expected client outcome. A dedicated `Retryable` marker was discarded — it would map to the same
 * status, need the same Sentry treatment, and have exactly one implementer.
 *
 * Untranslated, the same failure reaches the caller as a bare 500 `unhandled-exception`, which reads as "the
 * server is broken" and tells a client not to retry. The failure it matters most for is the GDPR erasure:
 * it runs the actor and resource audit passes and the `event_store` anonymiser in one transaction, and that
 * last one is a `payload::text ILIKE` — a sequential scan over rows the outbox is inserting into
 * concurrently, which is where contention is actually plausible.
 */
final class TransientTransactionFailure extends DomainException implements ServiceUnavailable
{
    public function __construct(Throwable $previous)
    {
        parent::__construct(
            type: 'transient-transaction-failure',
            title: 'The operation was interrupted by a concurrent one and can be retried.',
            previous: $previous,
        );
    }
}
