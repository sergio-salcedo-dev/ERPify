<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Domain\Event;

use DateTimeImmutable;
use Erpify\Shared\Domain\Event\DomainEvent;
use Override;

/**
 * Shared payload and serialization for bank lifecycle events that carry the full
 * bank snapshot. Concrete subclasses differ only in {@see eventName()}.
 */
abstract class AbstractBankChangedDomainEvent extends DomainEvent
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
        ?DateTimeImmutable $occurredOn = null,
    ) {
        parent::__construct(
            $bankId,
            $occurredOn ?? self::now(),
        );
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
