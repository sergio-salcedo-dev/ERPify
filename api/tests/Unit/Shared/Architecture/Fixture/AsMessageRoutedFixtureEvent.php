<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Architecture\Fixture;

use DateTimeImmutable;
use Erpify\Shared\Event\Domain\DomainEvent;
use Override;
use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * A person-aggregate event routed by attribute alone. `SendersLocator::send()` falls back to
 * `#[AsMessage(transport:)]` when no routing entry matched, so this shape reaches a persisted transport with
 * `framework.messenger.routing` completely empty — the one evasion vector that leaves no trace in the config
 * the gate reads. No event in `src` carries the attribute today, which is why proving the scan works needs a
 * fixture rather than a real class.
 *
 * @internal
 */
#[AsMessage(transport: 'async')]
final class AsMessageRoutedFixtureEvent extends DomainEvent
{
    #[Override]
    public static function eventName(): string
    {
        return 'erpify.tests.fixture.as-message-routed';
    }

    #[Override]
    public static function aggregateType(): string
    {
        return PersonAggregateFixtureEvent::AGGREGATE_TYPE;
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toPrimitives(): array
    {
        return [];
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
        return new self($aggregateId, $eventId, new DateTimeImmutable($occurredOn));
    }
}
