<?php

declare(strict_types=1);

namespace Erpify\Shared\Domain\Aggregate;

use DateTimeImmutable;
use Erpify\Shared\Domain\Entity\Identifiable;
use Erpify\Shared\Domain\Entity\Timestamped;
use Erpify\Shared\Domain\Event\DomainEvent;

/**
 * Collects domain events on the aggregate; the application layer should {@see pullDomainEvents()}
 * after persistence and publish them (e.g. MessageBus).
 */
abstract class AggregateRoot
{
    use Identifiable;
    use Timestamped;

    /** @var list<DomainEvent> */
    private array $domainEvents = [];

    protected function __construct()
    {
        $now = new DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * @return list<DomainEvent>
     */
    final public function pullDomainEvents(): array
    {
        $events = $this->domainEvents;

        $this->domainEvents = [];

        return $events;
    }

    final protected function record(DomainEvent $domainEvent): void
    {
        $this->domainEvents[] = $domainEvent;
    }
}
