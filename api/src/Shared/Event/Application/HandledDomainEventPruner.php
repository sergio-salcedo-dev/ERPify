<?php

declare(strict_types=1);

namespace Erpify\Shared\Event\Application;

use DateTimeImmutable;

/**
 * Prunes stale rows from the handler-idempotency claim store. A claim is only useful during the
 * Messenger redelivery window of its event; older rows are dead weight, so a periodic sweep keeps
 * `handled_domain_event` bounded. Deletion is idempotent — running it more often than scheduled is
 * harmless. See {@see DomainEventHandlerDeduplicator} for the claim/release side.
 */
interface HandledDomainEventPruner
{
    /**
     * Deletes claims first recorded before the threshold; returns the number of rows removed.
     */
    public function pruneClaimedBefore(DateTimeImmutable $threshold): int;
}
