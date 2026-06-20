<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Domain\Event;

use DateTimeImmutable;
use Erpify\Shared\Event\Domain\DomainEvent;
use Override;

final class BankUpdatedDomainEvent extends DomainEvent
{
    public function __construct(
        string $aggregateId,
        private readonly BankSnapshot $snapshot,
        ?string $eventId = null,
        ?DateTimeImmutable $occurredOn = null,
    ) {
        parent::__construct($aggregateId, $eventId, $occurredOn);
    }

    #[Override]
    public static function eventName(): string
    {
        return 'erpify.backoffice.bank.updated';
    }

    #[Override]
    public static function aggregateType(): string
    {
        return 'Backoffice.Bank';
    }

    /**
     * @return array<string, string|null>
     */
    #[Override]
    public function toPrimitives(): array
    {
        return $this->snapshot->toPrimitives();
    }

    /**
     * @param array<string, mixed> $body
     */
    #[Override]
    public static function fromPrimitives(
        string $aggregateId,
        array $body,
        string $eventId,
        string $occurredOn,
    ): static {
        return new self(
            $aggregateId,
            BankSnapshot::fromPrimitives($body),
            $eventId,
            new DateTimeImmutable($occurredOn),
        );
    }
}
