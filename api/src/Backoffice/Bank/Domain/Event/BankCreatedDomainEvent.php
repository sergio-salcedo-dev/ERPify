<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Domain\Event;

use DateTimeImmutable;
use Erpify\Shared\Domain\Event\DomainEvent;

final class BankCreatedDomainEvent extends DomainEvent
{
    public function __construct(
        string $bankId,
        private readonly string $name,
        private readonly string $shortName,
        private readonly string $createdAt,
        private readonly string $updatedAt,
        private readonly ?string $logoMediaId = null,
        private readonly ?string $logoContentHash = null,
        ?string $eventId = null,
        ?DateTimeImmutable $occurredOn = null,
    ) {
        parent::__construct(
            $bankId,
            $eventId ?? self::newEventId(),
            $occurredOn ?? self::now(),
        );
    }

    public static function eventName(): string
    {
        return 'erpify.backoffice.bank.created';
    }

    public function toPrimitives(): array
    {
        return [
            'bank_id' => $this->aggregateId(),
            'name' => $this->name,
            'short_name' => $this->shortName,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'logo_media_id' => $this->logoMediaId,
            'logo_content_hash' => $this->logoContentHash,
        ];
    }
}
