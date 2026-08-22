<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate\Fixture;

use DateTimeImmutable;
use Override;

/**
 * A person-aggregate event carrying no attribute of its own: its routing comes entirely from an abstract
 * parent and an interface, through a subclass of `AsMessage`. Symfony sends it to both transports; a gate
 * reflecting only the concrete class with a plain `AsMessage` filter sees none of it.
 *
 * @internal
 */
final class AsMessageInheritedFixtureEvent extends AbstractAsMessageCarrier implements AsMessageCarrierContract
{
    #[Override]
    public static function eventName(): string
    {
        return 'erpify.tests.fixture.as-message-inherited';
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
