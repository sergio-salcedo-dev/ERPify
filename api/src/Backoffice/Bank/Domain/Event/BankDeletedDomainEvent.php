<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Domain\Event;

use DateTimeImmutable;
use Erpify\Shared\Domain\Event\DomainEvent;
use Override;

final class BankDeletedDomainEvent extends DomainEvent
{
    public function __construct(
        string $bankId,
        string $eventId,
        ?DateTimeImmutable $occurredOn = null,
    ) {
        parent::__construct(
            $bankId,
            $eventId,
            $occurredOn ?? self::now(),
        );
    }

    #[Override]
    public static function eventName(): string
    {
        return 'erpify.backoffice.bank.deleted';
    }

    /**
     * @return array<string, string|null>
     */
    #[Override]
    public function toPrimitives(): array
    {
        return [
            'bankId' => $this->aggregateId(),
        ];
    }
}
