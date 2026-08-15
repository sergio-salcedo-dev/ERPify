<?php

declare(strict_types=1);

namespace Erpify\Shared\Event\Application;

use DateTimeImmutable;

/**
 * Prunes the Messenger failure transport past its retention window.
 *
 * `failed` is durable on purpose — a message that exhausts its retries waits there for an operator to see,
 * decide and replay it. A retention window does not soften that contract, it bounds it: past the window the
 * decision has been made by not being made, and the row is dead weight in a table that has no other ceiling.
 *
 * The window must stay far wider than the dead-letter alarm's age threshold. The alarm reports the age of the
 * OLDEST surviving row, so a prune that reaches rows the alarm has not yet complained about would cap the very
 * number the alarm exists to raise — the queue would go quiet because the evidence aged out, not because the
 * backlog cleared. That relationship is pinned by a test, not by hope.
 */
interface FailedMessagePruner
{
    /**
     * Deletes failed messages enqueued before the threshold; returns the number of rows removed.
     */
    public function pruneFailedBefore(DateTimeImmutable $threshold): int;
}
