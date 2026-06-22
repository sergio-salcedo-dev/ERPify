<?php

declare(strict_types=1);

namespace Erpify\Shared\Event\Application;

/**
 * Read access to the messages resting in the failure transport (Messenger's dead-letter queue).
 * Keeps inspection and alerting free of the transport: implementations adapt whatever failure
 * transport is configured, degrading to an empty view when it cannot be listed (e.g. the in-memory
 * transport used in tests).
 */
interface DeadLetterReader
{
    /**
     * Total number of messages in the failure transport (cheap; no payload hydration).
     */
    public function count(): int;

    /**
     * Resting messages projected to {@see DeadLetterEntry}, oldest first, capped at $limit when given.
     *
     * @return list<DeadLetterEntry>
     */
    public function entries(?int $limit = null): array;
}
