<?php

declare(strict_types=1);

namespace Erpify\Shared\Monitoring\Infrastructure\Sentry;

use Doctrine\DBAL\Exception\ConnectionException;
use Erpify\Shared\ErrorContract\Domain\Exception\ClientError;
use Sentry\Event;
use Sentry\EventHint;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\PostgreSqlConnection;
use Throwable;

/**
 * Sentry `before_send` filter. Drops two kinds of non-actionable events; everything else flows on
 * to {@see SentryEventScrubber} (composed by {@see SentryBeforeSend}). Returning `null` stops the
 * event from leaving the process, keeping error tracking signal-rich.
 *
 * 1. **Expected 4xx client errors** — any throwable implementing {@see ClientError} (a business
 *    outcome / invalid client request that needs no technical intervention, e.g. deleting a bank
 *    that still has accounts → 409). 5xx paths are untouched: a marker-less DomainException maps to
 *    `unhandled-exception` (500) and any non-domain throwable is not a ClientError — both keep
 *    flowing. The decision is type-based and reuses the RFC 9457 marker hierarchy as the single
 *    source of truth (see {@see ClientError}).
 *
 * 2. **Messenger-worker DB-connection teardown noise** — the long-lived `messenger:consume` worker
 *    holds a PostgreSQL `LISTEN messenger_messages` connection and runs an `UNLISTEN` in its
 *    shutdown destructor. When the stack goes down/restarts (a `compose down`, a deploy, a worktree
 *    teardown) Postgres terminates that backend mid-`LISTEN` (08006 `ConnectionLost`) and a beat
 *    later the `database` host no longer resolves on the destructor's `UNLISTEN` (08006
 *    `ConnectionException`, DNS failure). Both are lifecycle artifacts of a self-healing worker
 *    (`restart: unless-stopped` reconnects on the next boot), not application faults — their real
 *    signal is the container exit status, never Sentry. We drop a DBAL connection-loss ONLY when it
 *    originates from the worker's transport (the `messenger:consume` command tag, or a
 *    `PostgreSqlConnection` frame in the trace — the destructor path carries no command tag).
 *    A genuine DB outage during HTTP request handling has neither marker and still reaches Sentry.
 *    See docs/troubleshooting/sentry-boot-probe-noise.md.
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

    public function __invoke(Event $event, ?EventHint $hint): ?Event
    {
        $throwable = $hint?->exception;

        // Deliberate: only the top-level throwable is inspected, not the getPrevious() chain.
        // ClientError is thrown solely in HTTP request flows today, reaching the kernel
        // unwrapped — so a top-level check suffices. A ClientError surfacing WRAPPED (e.g. a
        // Messenger HandlerFailedException around one) is not self-evidently noise: in async it
        // can signal a malformed message, a publisher bug, or cross-context drift worth seeing.
        // Revisit (chain-walk vs. keep dropping) only when such a case actually occurs.
        if ($throwable instanceof ClientError) {
            return null;
        }

        if ($throwable instanceof Throwable && $this->isWorkerConnectionTeardown($event, $throwable)) {
            return null;
        }

        return $event;
    }

    private function isWorkerConnectionTeardown(Event $event, Throwable $throwable): bool
    {
        if (!$this->chainHasConnectionLoss($throwable)) {
            return false;
        }

        if ($this->isMessengerConsume($event)) {
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

    private function isMessengerConsume(Event $event): bool
    {
        return ($event->getTags()['console.command'] ?? null) === self::MESSENGER_CONSUME_COMMAND;
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
