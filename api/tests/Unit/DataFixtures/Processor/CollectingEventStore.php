<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\DataFixtures\Processor;

use Erpify\Shared\Event\Application\EventStore;
use Erpify\Shared\Event\Application\StoredEvent;
use Erpify\Shared\Event\Domain\DomainEvent;
use Override;

/**
 * Collects what a subject appends. A real double rather than a mock: the assertions are about the
 * events themselves, not about call counts on a doubled interface.
 */
final class CollectingEventStore implements EventStore
{
    /** @var list<DomainEvent> */
    public array $appended = [];

    #[Override]
    public function append(DomainEvent $event): void
    {
        $this->appended[] = $event;
    }

    /**
     * @param list<string> $eventNames
     *
     * @return iterable<StoredEvent>
     */
    #[Override]
    public function stream(int $afterSequence, array $eventNames, int $limit): iterable
    {
        return [];
    }
}
