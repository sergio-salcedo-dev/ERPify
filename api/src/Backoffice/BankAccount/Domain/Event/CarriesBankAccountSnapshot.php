<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Domain\Event;

use DateTimeImmutable;
use Override;

/**
 * Shared envelope for the bank-account lifecycle events that carry a {@see BankAccountSnapshot}
 * (created, updated, deleted) — otherwise byte-identical bar their event name. Horizontal composition
 * keeps each event a direct DomainEvent child with no common supertype, while the snapshot still owns
 * the payload serialization contract.
 */
trait CarriesBankAccountSnapshot
{
    public function __construct(
        string $aggregateId,
        private readonly BankAccountSnapshot $snapshot,
        ?string $eventId = null,
        ?DateTimeImmutable $occurredOn = null,
    ) {
        parent::__construct($aggregateId, $eventId, $occurredOn);
    }

    #[Override]
    public static function aggregateType(): string
    {
        return 'Backoffice.BankAccount';
    }

    /**
     * Owning bank id, read off the snapshot so the realtime publisher can address the per-bank
     * collection topic without re-querying.
     */
    public function bankId(): string
    {
        return $this->snapshot->bankId;
    }

    /**
     * @return array<string, string>
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
            BankAccountSnapshot::fromPrimitives($body),
            $eventId,
            new DateTimeImmutable($occurredOn),
        );
    }
}
