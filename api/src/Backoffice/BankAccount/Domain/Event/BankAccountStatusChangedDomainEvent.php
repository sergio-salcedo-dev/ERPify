<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Domain\Event;

use DateTimeImmutable;
use Erpify\Shared\Event\Domain\DomainEvent;
use Override;

/**
 * Records a lifecycle transition of a bank account as a first-class business fact, distinct from a
 * descriptive edit ({@see BankAccountUpdatedDomainEvent}). Its payload is PII-free — only the owning
 * `bankId` (for the per-bank realtime topic) and the `from`/`to` status values; never IBAN / holder /
 * BIC / alias. It does not compose {@see BankAccountSnapshot} because a transition is described by its
 * endpoints, not by the current snapshot.
 */
final class BankAccountStatusChangedDomainEvent extends DomainEvent
{
    public function __construct(
        string $aggregateId,
        private readonly string $bankId,
        private readonly string $fromStatus,
        private readonly string $toStatus,
        ?string $eventId = null,
        ?DateTimeImmutable $occurredOn = null,
    ) {
        parent::__construct($aggregateId, $eventId, $occurredOn);
    }

    #[Override]
    public static function eventName(): string
    {
        return 'erpify.backoffice.bankaccount.status_changed';
    }

    #[Override]
    public static function aggregateType(): string
    {
        return 'Backoffice.BankAccount';
    }

    /**
     * Owning bank id, so the realtime publisher can address the per-bank collection topic without
     * re-querying.
     */
    public function bankId(): string
    {
        return $this->bankId;
    }

    public function fromStatus(): string
    {
        return $this->fromStatus;
    }

    public function toStatus(): string
    {
        return $this->toStatus;
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    public function toPrimitives(): array
    {
        return [
            'bankId' => $this->bankId,
            'fromStatus' => $this->fromStatus,
            'toStatus' => $this->toStatus,
        ];
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
            self::stringField($body, 'bankId'),
            self::stringField($body, 'fromStatus'),
            self::stringField($body, 'toStatus'),
            $eventId,
            new DateTimeImmutable($occurredOn),
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function stringField(array $body, string $key): string
    {
        $value = $body[$key] ?? null;

        return \is_string($value) ? $value : '';
    }
}
