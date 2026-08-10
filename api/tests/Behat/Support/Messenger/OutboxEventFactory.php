<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Support\Messenger;

use DateTimeImmutable;
use DateTimeInterface;
use Erpify\Shared\Event\Domain\DomainEvent;
use Erpify\Shared\Uuid\Domain\Uuid;
use InvalidArgumentException;
use JsonException;

/**
 * Rebuilds a typed {@see DomainEvent} from the stored-row-shaped JSON a scenario writes.
 *
 * Reconstruction goes through the domain's own {@see DomainEvent::fromPrimitives()}, so a scenario
 * cannot mint an event by a route the application does not have. `aggregateId` is required; `eventId`
 * and `occurredOn` default when omitted, because a scenario that does not care which id an event
 * carries should not have to invent one.
 *
 * Separate from the context that dispatches it: turning JSON into an event is construction, and the
 * context's job is turning outbox reads into assertions.
 */
final class OutboxEventFactory
{
    /**
     * @param class-string $fullyQualifiedClassName
     *
     * @throws InvalidArgumentException when the class is not a domain event
     * @throws JsonException            when the payload is not valid JSON
     */
    public static function fromJson(string $fullyQualifiedClassName, string $rawJson): DomainEvent
    {
        if (!\is_a($fullyQualifiedClassName, DomainEvent::class, true)) {
            throw new InvalidArgumentException(
                \sprintf('"%s" is not a %s', $fullyQualifiedClassName, DomainEvent::class),
            );
        }

        /** @var array<string, mixed> $decoded */
        $decoded = (array) \json_decode($rawJson, true, 512, JSON_THROW_ON_ERROR);

        $payload = [];

        if (\is_array($decoded['payload'] ?? null)) {
            foreach ($decoded['payload'] as $key => $value) {
                $payload[(string) $key] = $value;
            }
        }

        return $fullyQualifiedClassName::fromPrimitives(
            \is_string($decoded['aggregateId'] ?? null) ? $decoded['aggregateId'] : '',
            $payload,
            \is_string($decoded['eventId'] ?? null) ? $decoded['eventId'] : Uuid::generate(),
            \is_string($decoded['occurredOn'] ?? null)
                ? $decoded['occurredOn']
                : (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
        );
    }
}
