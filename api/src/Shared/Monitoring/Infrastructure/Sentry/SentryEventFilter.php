<?php

declare(strict_types=1);

namespace Erpify\Shared\Monitoring\Infrastructure\Sentry;

use Doctrine\DBAL\Exception\ConnectionException;
use Erpify\Shared\ErrorContract\Domain\Exception\ClientError;
use Erpify\Shared\ErrorContract\Domain\Exception\Conflict;
use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;
use Erpify\Shared\ErrorContract\Domain\Exception\Forbidden;
use Erpify\Shared\ErrorContract\Domain\Exception\InvalidInput;
use Erpify\Shared\ErrorContract\Domain\Exception\InvalidSearchCriteria;
use Erpify\Shared\ErrorContract\Domain\Exception\InvariantViolation;
use Erpify\Shared\ErrorContract\Domain\Exception\NotFound;
use Erpify\Shared\ErrorContract\Domain\Exception\RateLimited;
use Erpify\Shared\ErrorContract\Domain\Exception\ServiceUnavailable;
use Erpify\Shared\ErrorContract\Domain\Exception\Unauthenticated;
use Erpify\Shared\Monitoring\Domain\RoutineOutcome;
use Sentry\Event;
use Sentry\EventHint;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\PostgreSqlConnection;
use Symfony\Component\Messenger\Exception\TransportException;
use Throwable;

/**
 * Sentry `before_send` filter. Drops two kinds of non-actionable events; everything else flows on
 * to {@see SentryEventScrubber} (composed by {@see SentryBeforeSend}). Returning `null` stops the
 * event from leaving the process, keeping error tracking signal-rich.
 *
 * 1. **Expected 4xx business outcomes on an HTTP request** — a {@see DomainException} implementing one
 *    of {@see EXPECTED_OUTCOME_MARKERS} (404, 409, 422, 400: a business outcome or an invalid request
 *    that needs no technical intervention, e.g. deleting a bank that still has accounts → 409) and
 *    none of {@see SIGNAL_MARKERS}. The security markers — `Unauthenticated` (401), `Forbidden` (403),
 *    `RateLimited` (429) — are reported although they are client errors: a spike of failed proofs,
 *    lockouts or throttling is how an attack shows up, and so is a spike of the framework's own 4xx
 *    (Security 401/403, validation 422, a 4xx `HttpException`), which carry no marker and always flow.
 *    `ServiceUnavailable` sits there too, because an exception combining it with an outcome marker may
 *    be answered 503 depending on marker order. 5xx paths are untouched: a marker-less DomainException
 *    maps to `unhandled-exception` (500), and a marker on a non-domain throwable is honoured by no one —
 *    `ProblemDetailsFactory` answers it 500. A {@see ClientError} marker on neither list is reported, so
 *    a marker added later fails toward reporting until someone classifies it.
 *
 *    A {@see RoutineOutcome} is dropped too, and it outranks the signal markers: it is the exception whose
 *    volume follows normal traffic and whose hostile occurrence looks exactly like a benign one — an
 *    expired session's 401 — so its issue could never alert and would spend the quota the signals need.
 *    It reaches no 5xx: it is dropped only as a `DomainException` beside one of the status-bearing 4xx
 *    markers on the two lists — a bare `ClientError` maps no status, and the contract answers it 500.
 *
 *    **Only where a client exists**, read as an event carrying request data (Sentry's
 *    `RequestIntegration` fills it before `before_send` runs). Sentry's Messenger listener unwraps a
 *    `HandlerFailedException` and captures each nested throwable top-level, so a handler's `NotFound`
 *    arrives here with the very type a client's has — and there it is a malformed message, a publisher
 *    bug or cross-context drift, never a client mistake. A console command has no client either.
 *
 * 2. **Messenger-worker DB-connection teardown noise** — the long-lived `messenger:consume` worker
 *    holds a PostgreSQL `LISTEN messenger_messages` connection and runs an `UNLISTEN` in its
 *    shutdown destructor. When the stack goes down/restarts (a `compose down`, a deploy, a worktree
 *    teardown) Postgres terminates that backend mid-`LISTEN` (08006 `ConnectionLost`) and a beat
 *    later the `database` host no longer resolves on the destructor's `UNLISTEN` (08006
 *    `ConnectionException`, DNS failure). Both are lifecycle artifacts of a self-healing worker
 *    (`restart: unless-stopped` reconnects on the next boot), not application faults — their real
 *    signal is the container exit status, never Sentry. We drop a DBAL connection-loss ONLY when it
 *    originates from the worker's transport: either a `TransportException` raised while the worker
 *    was polling (the `LISTEN` path, which carries the `messenger:consume` command tag) or a
 *    `PostgreSqlConnection` frame in the trace (the destructor `UNLISTEN` path, which carries no tag).
 *    A genuine DB outage during HTTP request handling has neither marker and still reaches Sentry.
 *    See docs/troubleshooting/sentry-boot-probe-noise.md.
 *
 *    **The command tag alone is not the marker, and the difference is load-bearing.** A worker
 *    process is not a single tenant: `messenger:consume` is also the command line of the scheduler
 *    worker, so every handler running under it would inherit the suppression. A maintenance control
 *    that throws because its own read lost the connection is the opposite of teardown noise — it is
 *    a control that could not run, which is precisely what must page someone. Discriminating by the
 *    exception the transport itself raises keeps the drop tied to the origin rather than the process,
 *    and a process gains tenants while an origin does not.
 */
final class SentryEventFilter
{
    /**
     * The Doctrine Messenger transport connection that issues the worker's `LISTEN`/`UNLISTEN
     * messenger_messages`. Its presence in the throwable's stack trace scopes the drop to the
     * worker's polling loop (and its shutdown destructor), never an HTTP-request DB path.
     */
    private const string MESSENGER_WORKER_TRANSPORT = PostgreSqlConnection::class;

    private const string MESSENGER_CONSUME_COMMAND = 'messenger:consume';

    /**
     * Client-error markers whose 4xx is an expected business outcome, dropped on an HTTP request.
     */
    public const array EXPECTED_OUTCOME_MARKERS = [
        NotFound::class,
        Conflict::class,
        InvariantViolation::class,
        InvalidInput::class,
        InvalidSearchCriteria::class,
    ];

    /**
     * Markers that keep an exception reported even beside an expected-outcome marker.
     */
    public const array SIGNAL_MARKERS = [
        Unauthenticated::class,
        Forbidden::class,
        RateLimited::class,
        ServiceUnavailable::class,
    ];

    public function __invoke(Event $event, ?EventHint $hint): ?Event
    {
        $throwable = $hint?->exception;

        if (
            $throwable instanceof DomainException
            && [] !== $event->getRequest()
            && $this->isRoutineOrExpected($throwable)
        ) {
            return null;
        }

        if ($throwable instanceof Throwable && $this->isWorkerConnectionTeardown($event, $throwable)) {
            return null;
        }

        return $event;
    }

    private function isRoutineOrExpected(DomainException $exception): bool
    {
        $implements = static fn (string $marker): bool => $exception instanceof $marker;

        if ($exception instanceof RoutineOutcome) {
            return !$exception instanceof ServiceUnavailable
                && \array_any([...self::EXPECTED_OUTCOME_MARKERS, ...self::SIGNAL_MARKERS], $implements);
        }

        return \array_any(self::EXPECTED_OUTCOME_MARKERS, $implements)
            && !\array_any(self::SIGNAL_MARKERS, $implements);
    }

    private function isWorkerConnectionTeardown(Event $event, Throwable $throwable): bool
    {
        if (!$this->chainHasConnectionLoss($throwable)) {
            return false;
        }

        if ($this->isTransportPollingFailure($throwable, $event)) {
            return true;
        }

        return $this->chainTraceMentionsWorkerTransport($throwable);
    }

    private function chainHasConnectionLoss(Throwable $throwable): bool
    {
        // `ConnectionLost` extends `ConnectionException`, so one `instanceof` covers both the
        // mid-`LISTEN` kill and the `UNLISTEN` DNS failure. Matched anywhere in the chain — a
        // Messenger `TransportException` wraps the DBAL exception as its previous.
        return \array_any(
            $this->chain($throwable),
            static fn (Throwable $link): bool => $link instanceof ConnectionException,
        );
    }

    private function chainTraceMentionsWorkerTransport(Throwable $throwable): bool
    {
        foreach ($this->chain($throwable) as $link) {
            foreach ($link->getTrace() as $frame) {
                if (($frame['class'] ?? null) === self::MESSENGER_WORKER_TRANSPORT) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The `LISTEN` half: the transport's own polling call fails and Messenger surfaces it as a
     * {@see TransportException}. The command tag scopes it to the worker; the exception type scopes it
     * to the transport, which is what keeps a handler's own connection loss — a control that could not
     * run — out of the suppression even though it shares the process and therefore the tag.
     */
    private function isTransportPollingFailure(Throwable $throwable, Event $event): bool
    {
        return $throwable instanceof TransportException
            && ($event->getTags()['console.command'] ?? null) === self::MESSENGER_CONSUME_COMMAND;
    }

    /**
     * The throwable plus its `getPrevious()` chain, with a cycle guard against self-referential
     * wrapping.
     *
     * @return list<Throwable>
     */
    private function chain(Throwable $throwable): array
    {
        $chain = [];

        for ($link = $throwable; $link instanceof Throwable; $link = $link->getPrevious()) {
            if (\in_array($link, $chain, true)) {
                break;
            }

            $chain[] = $link;
        }

        return $chain;
    }
}
