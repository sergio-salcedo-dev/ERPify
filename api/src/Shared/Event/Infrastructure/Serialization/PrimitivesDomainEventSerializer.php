<?php

declare(strict_types=1);

namespace Erpify\Shared\Event\Infrastructure\Serialization;

use Erpify\Shared\Event\Application\DomainEventSerializer;
use Erpify\Shared\Event\Domain\DomainEvent;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * {@link DomainEventSerializer} that maps the event's own {@see DomainEvent::toPrimitives()} to the
 * `payload` column. `metadata` is empty today (correlation/causation/actor reserved — ADR D9).
 */
#[AsAlias(DomainEventSerializer::class)]
final readonly class PrimitivesDomainEventSerializer implements DomainEventSerializer
{
    #[Override]
    public function serialize(DomainEvent $event): array
    {
        return [
            'payload' => $event->toPrimitives(),
            'metadata' => [],
        ];
    }
}
