<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate\Fixture;

use DateTimeImmutable;
use Erpify\Shared\Event\Domain\DomainEvent;
use Override;

/**
 * A person-aggregate event that lives outside `src`, so the persistent-transport gate can be driven against
 * every routing shape Messenger accepts without a dirty routing entry ever existing in the real config.
 *
 * It deliberately reports an aggregate type the policy classifies as a person: the gate's whole subject is
 * what happens when such an event reaches a persisted transport.
 *
 * @internal
 */
final class PersonAggregateFixtureEvent extends DomainEvent implements PersonScopedFixtureEvent
{
    public const string AGGREGATE_TYPE = 'Iam.Identity';

    #[Override]
    public static function eventName(): string
    {
        return 'erpify.tests.fixture.person-aggregate';
    }

    #[Override]
    public static function aggregateType(): string
    {
        return self::AGGREGATE_TYPE;
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
