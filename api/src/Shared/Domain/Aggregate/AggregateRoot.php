<?php

declare(strict_types=1);

namespace Erpify\Shared\Domain\Aggregate;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Erpify\Shared\Domain\Event\DomainEvent;
use Symfony\Bridge\Doctrine\IdGenerator\UuidGenerator;
use Symfony\Component\Serializer\Attribute as Serializer;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Collects domain events on the aggregate; the application layer should {@see pullDomainEvents()}
 * after persistence and publish them (e.g. MessageBus).
 */
abstract class AggregateRoot
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UuidGenerator::class)]
    #[Assert\Uuid]
    #[Serializer\Groups(['aggregate:default'])]
    protected ?string $id = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    #[Serializer\Context([DateTimeNormalizer::FORMAT_KEY => 'Y-m-d\TH:i:sP'])]
    #[Serializer\Groups(['aggregate:default'])]
    protected DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    #[Serializer\Context([DateTimeNormalizer::FORMAT_KEY => 'Y-m-d\TH:i:sP'])]
    #[Serializer\Groups(['aggregate:default'])]
    protected DateTimeImmutable $updatedAt;

    /** @var list<DomainEvent> */
    private array $domainEvents = [];

    public function getId(): ?string
    {
        return $this->id;
    }

    public function setId(?string $id): static
    {
        $this->id = $id;

        return $this;
    }

    /**
     * @return list<DomainEvent>
     */
    final public function pullDomainEvents(): array
    {
        $events = $this->domainEvents;

        $this->domainEvents = [];

        return $events;
    }

    final protected function record(DomainEvent $domainEvent): void
    {
        $this->domainEvents[] = $domainEvent;
    }
}
