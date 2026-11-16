<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Images\Application\Double;

use Erpify\Shared\Event\Domain\DomainEvent;
use Erpify\Shared\Event\Domain\EventBus;
use LogicException;
use Override;
use RuntimeException;

/**
 * An {@see EventBus} that, like the production guard in front of the real one, refuses a publication outside a unit
 * of work, and keeps what it was handed so a test reads what a committed unit queued.
 *
 * @internal
 */
final class TransactionalEventBus implements EventBus, Snapshottable
{
    /** @var list<DomainEvent> */
    public array $published = [];

    /** Raised by `publish` when set, so a case can fail the compensation itself. */
    public ?RuntimeException $failsWith = null;

    public function __construct(private readonly RollbackingTransactionManager $transactions)
    {
        $transactions->track($this);
    }

    #[Override]
    public function publish(DomainEvent ...$events): void
    {
        if ($this->failsWith instanceof RuntimeException) {
            throw $this->failsWith;
        }

        if (0 === $this->transactions->current) {
            throw new LogicException('Published outside a transaction.');
        }

        \array_push($this->published, ...$events);
    }

    /**
     * @return list<string> the aggregate ids of what is queued, in order
     */
    public function queuedIds(): array
    {
        return \array_map(static fn (DomainEvent $event): string => $event->aggregateId(), $this->published);
    }

    #[Override]
    public function snapshot(): mixed
    {
        return $this->published;
    }

    #[Override]
    public function restore(mixed $snapshot): void
    {
        \assert(\is_array($snapshot));
        /** @var list<DomainEvent> $snapshot */
        $this->published = $snapshot;
    }
}
