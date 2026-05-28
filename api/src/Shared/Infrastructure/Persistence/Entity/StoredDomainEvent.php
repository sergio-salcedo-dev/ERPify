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
        // NOSONAR: Doctrine populates via reflection; audit-table write-only field.
        #[ORM\Column(length: 190)]
        private string $name,
        // NOSONAR: Doctrine populates via reflection; audit-table write-only field.
        #[ORM\Column(length: 36)]
        private string $aggregateId,
        // NOSONAR: Doctrine populates via reflection; audit-table write-only field.
        #[ORM\Column(length: 36)]
        private string $eventId,
        // NOSONAR: Doctrine populates via reflection; audit-table write-only field.
        #[ORM\Column]
        private DateTimeImmutable $occurredOn,
        // NOSONAR: Doctrine populates via reflection; audit-table write-only field.
        #[ORM\Column(type: Types::JSON)]
        private array $body,
    ) {
    }
}
