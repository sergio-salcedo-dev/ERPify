<?php

declare(strict_types=1);

namespace Erpify\Shared\Images\Application;

use DateTimeImmutable;
use Erpify\Shared\Clock\Domain\Clock;

/**
 * One signal per distinct failure per window, so a caller cannot decide how much this deployment logs.
 *
 * **The volume of an observability sink became client-controlled the moment a route made these failures
 * reachable.** Three read-path states are permanent — an oversize row, a digest mismatch, bytes that are
 * gone — so every request against one of them is another line, and the global limiter admits 120 requests
 * per minute per IP. The sink is the container log: bounded by VOLUME rather than by age, with no TTL and
 * no owner of erasure, and evicted FIFO. A loop over one broken image therefore displaces the history of
 * subsystems that have nothing to do with it, and nothing alerts, because no collector reads that channel.
 *
 * The signal is named `(operation, category)` and deliberately not by the identifier. Naming it by the
 * identifier would
 * make the map itself the unbounded thing — cardinality supplied by the caller — which is the defect one
 * level down rather than a fix.
 *
 * **What this does NOT bound, said because "a 60-second window" reads stronger than it is.** The state is
 * process memory. FrankenPHP runs several workers per core and `FRANKENPHP_RESET_KERNEL` is unset, so the
 * container survives between requests within a worker and dies with it — the bound is therefore one line
 * per signal per window PER WORKER, and the aggregate scales with the worker count. It is a large reduction
 * from one line per request, not a global guarantee, and reading it as one is the mistake this paragraph
 * exists to prevent. A genuinely global bound needs shared state, which buys a network round trip on a
 * failure path to bound a log.
 *
 * The frequency information the log stops carrying is not recovered here: what an operator loses is how
 * OFTEN a permanent failure was retried, and what they keep is that it is still happening.
 */
final class FailureSignalWindow
{
    private const int WINDOW_SECONDS = 60;

    /** @var array<string, DateTimeImmutable> */
    private array $lastEmitted = [];

    public function __construct(private readonly Clock $clock)
    {
    }

    /**
     * True when the caller may emit. The stamp is taken here rather than after a successful emit, so a
     * sink that throws cannot turn every subsequent request back into a line.
     */
    public function admits(string $signal): bool
    {
        $now = $this->clock->now();
        $previous = $this->lastEmitted[$signal] ?? null;

        if (null !== $previous && $now->getTimestamp() - $previous->getTimestamp() < self::WINDOW_SECONDS) {
            return false;
        }

        $this->lastEmitted[$signal] = $now;

        return true;
    }
}
