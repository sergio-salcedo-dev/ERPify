<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Domain\Event;

use DateTimeImmutable;
use Erpify\Shared\Domain\Event\DomainEvent;
use Override;

final class BankUpdatedDomainEvent extends DomainEvent
{
    public function __construct(
        string $bankId,
        private readonly string $name,
        private readonly string $shortName,
        private readonly string $createdAt,
        private readonly string $updatedAt,
        private readonly ?string $logoMediaId = null,
        private readonly ?string $logoContentHash = null,
        private readonly ?string $storedObjectContentHash = null,
        private readonly ?string $storedObjectMimeType = null,
        ?string $eventId = null,
        ?DateTimeImmutable $occurredOn = null,
    ) {
        parent::__construct(
            $bankId,
            $eventId ?? self::newEventId(),
            $occurredOn ?? self::now(),
        );
    }

    #[Override]
    public static function eventName(): string
    {
        return 'erpify.backoffice.bank.updated';
    }

    /**
     * @return array<string, string|null>
     */
    #[Override]
    public function toPrimitives(): array
    {
        return [
            'bankId' => $this->aggregateId(),
            'name' => $this->name,
            'shortName' => $this->shortName,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
            'logoMediaId' => $this->logoMediaId,
            'logoContentHash' => $this->logoContentHash,
            'storedObjectContentHash' => $this->storedObjectContentHash,
            'storedObjectMimeType' => $this->storedObjectMimeType,
        ];
    }
}
