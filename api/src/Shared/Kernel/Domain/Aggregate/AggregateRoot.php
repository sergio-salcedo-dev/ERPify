<?php

declare(strict_types=1);

namespace Erpify\Shared\Kernel\Domain\Aggregate;

use DateTimeImmutable;
use Erpify\Shared\Event\Domain\DomainEvent;
use Erpify\Shared\Kernel\Domain\Entity\Identifiable;
use Erpify\Shared\Kernel\Domain\Entity\Timestamped;
use LogicException;

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

    /**
     * The instant is handed in by the application layer, which reads its injected clock once per
     * operation: an aggregate never decides what time it is, so its stamps are a function of its inputs.
     */
    protected function __construct(DateTimeImmutable $now)
    {
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

    /**
     * Non-null id of an identified aggregate. The application layer assigns the UUID v7 before
     * persist ({@see Identifiable::$id}), so by the time domain behaviour records events the id is
     * always present. Guards that invariant explicitly instead of leaking the nullable backing
     * property into the non-null `string $aggregateId` domain-event constructors.
     */
    final protected function id(): string
    {
        if (null === $this->id) {
            throw new LogicException(\sprintf('%s has no id assigned.', static::class));
        }

        return $this->id;
    }
}
