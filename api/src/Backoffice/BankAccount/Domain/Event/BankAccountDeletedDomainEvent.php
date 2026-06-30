<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Domain\Event;

use DateTimeImmutable;
use Erpify\Shared\Event\Domain\DomainEvent;
use Override;

/**
 * Unlike a bank deletion — addressed solely by the aggregate id — an account deletion still carries
 * the PII-free snapshot: the realtime collection topic is keyed by the owning `bankId` (not the
 * account id), so the publisher needs it to notify the bank's accounts list.
 */
final class BankAccountDeletedDomainEvent extends DomainEvent
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
    public static function eventName(): string
    {
        return 'erpify.backoffice.bankaccount.deleted';
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
