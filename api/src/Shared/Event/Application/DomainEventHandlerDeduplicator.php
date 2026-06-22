<?php

declare(strict_types=1);

namespace Erpify\Shared\Event\Application;

/**
 * Idempotency guard for event handlers with non-transactional side effects (email, external
 * APIs): Messenger delivery is at-least-once, so a handler that must act at most once claims
 * its `(eventId, handler)` pair before acting and releases it when the action fails, keeping
 * the transport retry path open. Handlers whose side effect is naturally idempotent
 * (Mercure publish, upsert) do not need this.
 */
interface DomainEventHandlerDeduplicator
{
    /**
     * Atomically claims the pair; returns true only for the first claimant.
     */
    public function claim(string $eventId, string $handlerKey): bool;

    /**
     * Releases a claim so a retry can claim it again (call only when the side effect failed).
     *
     * @return int the number of claims released — 0 when no matching claim existed
     */
    public function release(string $eventId, string $handlerKey): int;
}
