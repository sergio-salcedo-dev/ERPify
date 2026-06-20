<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Domain\Event;

use DateTimeImmutable;
use Erpify\Shared\Event\Domain\DomainEvent;
use Override;

/**
 * Carries no snapshot — a deletion is identified by its aggregate id alone, so the payload is empty.
 */
final class BankDeletedDomainEvent extends DomainEvent
{
    #[Override]
    public static function eventName(): string
    {
        return 'erpify.backoffice.bank.deleted';
    }

    #[Override]
    public static function aggregateType(): string
    {
        return 'Backoffice.Bank';
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toPrimitives(): array
    {
        return [];
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
        return new self($aggregateId, $eventId, new DateTimeImmutable($occurredOn));
    }
}
