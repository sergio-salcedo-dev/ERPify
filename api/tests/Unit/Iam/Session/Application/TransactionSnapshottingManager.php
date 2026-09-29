<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Session\Application;

use Erpify\Shared\Persistence\Application\TransactionManager;
use Override;

/**
 * Runs the unit of work inline and records what it did while inside it, so a test can tell a write or an event
 * made within the transaction from one made after it commits.
 *
 * @internal
 */
final class TransactionSnapshottingManager implements TransactionManager
{
    public int $calls = 0;

    public int $savedWithin = 0;

    public int $publishedWithin = 0;

    public function __construct(
        private readonly InMemorySessionRepository $sessions,
        private readonly RecordingEventBus $eventBus,
    ) {
    }

    #[Override]
    public function transactional(callable $operation): mixed
    {
        ++$this->calls;
        $saved = \count($this->sessions->saved);
        $published = \count($this->eventBus->publishedEvents);

        $result = $operation();

        $this->savedWithin += \count($this->sessions->saved) - $saved;
        $this->publishedWithin += \count($this->eventBus->publishedEvents) - $published;

        return $result;
    }
}
