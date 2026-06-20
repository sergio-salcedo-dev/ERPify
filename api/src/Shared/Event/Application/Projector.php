<?php

declare(strict_types=1);

namespace Erpify\Shared\Event\Application;

use Erpify\Shared\Event\Domain\DomainEvent;

/**
 * Deterministic, idempotent derivation of a read model from the event log — the opposite of a
 * reactor (external side effect, live-only, never replayed). A projector is replayable from
 * `sequence` 0; ordering and exactly-once application are guaranteed by the checkpoint, not by the
 * projector. Implementations tag themselves `erpify.projector` (see config/services.yaml).
 */
interface Projector
{
    /**
     * Stable projection name; the checkpoint row and `event:projection:rebuild` argument key off it.
     */
    public function name(): string;

    /**
     * Event names this projector consumes; the runner narrows the stream to them.
     *
     * @return list<string>
     */
    public function subscribedTo(): array;

    public function project(DomainEvent $event): void;

    /**
     * Resets the read model to its zero state, for a rebuild before replaying from `sequence` 0.
     */
    public function reset(): void;
}
