<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Support;

use DateTimeImmutable;
use Erpify\Shared\Domain\Event\DomainEvent;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Rebuilds a typed {@see DomainEvent} from a JSON payload via reflection over its constructor —
 * test-side only, so the domain needs no `fromPrimitives()`. Required constructor parameters must be
 * present in the payload; parameters carrying a default fall back to it when absent; a `null` payload
 * value is honoured for nullable parameters.
 *
 * The payload is therefore a *superset* of {@see DomainEvent::toPrimitives()}: that method omits
 * `occurredOn` (it lives on the envelope, not the payload), yet `AbstractBankChangedDomainEvent`'s
 * constructor requires it, so a dispatch must pass `occurredOn` explicitly. `occurredOn` and any
 * other `DateTimeImmutable` parameter is cast from its string form; scalars are coerced to the
 * declared type. Enum / UUID / value-object casts are added when a second aggregate needs them.
 */
final class EventHydrator
{
    /**
     * @param array<array-key, mixed> $payload
     */
    public function hydrate(string $fullyQualifiedClassName, array $payload): DomainEvent
    {
        if (!\class_exists($fullyQualifiedClassName)) {
            throw new InvalidArgumentException(\sprintf('The class "%s" does not exist', $fullyQualifiedClassName));
        }

        $reflection = new ReflectionClass($fullyQualifiedClassName);
        $constructor = $reflection->getConstructor();

        $arguments = [];

        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            $name = $parameter->getName();

            if (!\array_key_exists($name, $payload)) {
                if ($parameter->isDefaultValueAvailable()) {
                    $arguments[$name] = $parameter->getDefaultValue();

                    continue;
                }

                throw new InvalidArgumentException(
                    \sprintf('Required constructor argument "%s" is missing from the payload', $name),
                );
            }

            $type = $parameter->getType();
            $value = $payload[$name];

            if (null === $value && true === $type?->allowsNull()) {
                $arguments[$name] = null;

                continue;
            }

            $arguments[$name] = $this->cast($value, $type instanceof ReflectionNamedType ? $type : null);
        }

        $event = $reflection->newInstanceArgs($arguments);

        if (!$event instanceof DomainEvent) {
            throw new InvalidArgumentException(
                \sprintf('The class "%s" is not a %s', $fullyQualifiedClassName, DomainEvent::class),
            );
        }

        return $event;
    }

    private function cast(mixed $value, ?ReflectionNamedType $type): mixed
    {
        $typeName = $type?->getName();

        if (DateTimeImmutable::class === $typeName) {
            return new DateTimeImmutable(\is_string($value) ? $value : 'now');
        }

        if (!\is_scalar($value)) {
            return $value;
        }

        return match ($typeName) {
            'int' => (int) $value,
            'float' => (float) $value,
            'bool' => (bool) $value,
            'string' => (string) $value,
            default => $value,
        };
    }
}
