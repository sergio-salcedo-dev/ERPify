<?php

declare(strict_types=1);

namespace Erpify\Shared\Infrastructure\Persistence\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Erpify\Shared\Domain\Entity\Identifiable;

#[ORM\Entity]
#[ORM\Table(name: 'domain_event')]
#[ORM\Index(name: 'domain_event_aggregate_id_idx', fields: ['aggregateId'])]
#[ORM\Index(name: 'domain_event_name_idx', fields: ['name'])]
class StoredDomainEvent
{
    use Identifiable;

    /**
     * @param array<string, mixed> $body
     */
    public function __construct(
        string $id,
        #[ORM\Column(length: 190)]
        private string $name,
        #[ORM\Column(length: 36)]
        private string $aggregateId,
        #[ORM\Column(length: 36)]
        private string $eventId,
        #[ORM\Column]
        private DateTimeImmutable $occurredOn,
        #[ORM\Column(type: Types::JSON)]
        private array $body,
    ) {
        // The id is no longer Doctrine-generated (the shared Identifiable trait dropped its
        // CustomIdGenerator); the store must assign one so the row is never persisted without a PK.
        $this->id = $id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function aggregateId(): string
    {
        return $this->aggregateId;
    }

    public function eventId(): string
    {
        return $this->eventId;
    }

    public function occurredOn(): DateTimeImmutable
    {
        return $this->occurredOn;
    }

    /**
     * @return array<string, mixed>
     */
    public function body(): array
    {
        return $this->body;
    }
}
