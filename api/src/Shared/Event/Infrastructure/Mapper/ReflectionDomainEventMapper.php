<?php

declare(strict_types=1);

namespace Erpify\Shared\Event\Infrastructure\Mapper;

use Erpify\Shared\Event\Domain\DomainEvent;
use Erpify\Shared\Event\Domain\DomainEventMapper;
use InvalidArgumentException;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * {@link DomainEventMapper} whose `(eventName, eventVersion) ⇒ class` and `⇒ aggregateType` tables are
 * built once at compile time by {@see \Erpify\Shared\Event\Infrastructure\DependencyInjection\RegisterDomainEventsPass}
 * from a reflection scan of every concrete {@see DomainEvent} (which also fails the build on an
 * `eventName` collision). The resolution itself is a constant-time lookup, no runtime reflection.
 */
#[AsAlias(DomainEventMapper::class)]
final readonly class ReflectionDomainEventMapper implements DomainEventMapper
{
    /**
     * @param array<string, class-string<DomainEvent>> $classByKey         keyed `eventName:eventVersion`
     * @param array<string, string>                    $aggregateTypeByKey keyed `eventName:eventVersion`
     */
    public function __construct(
        private array $classByKey,
        private array $aggregateTypeByKey,
    ) {
    }

    #[Override]
    public function classFor(string $eventName, int $eventVersion): string
    {
        return $this->classByKey[$this->key($eventName, $eventVersion)]
            ?? throw $this->unknown($eventName, $eventVersion);
    }

    #[Override]
    public function aggregateTypeFor(string $eventName, int $eventVersion): string
    {
        return $this->aggregateTypeByKey[$this->key($eventName, $eventVersion)]
            ?? throw $this->unknown($eventName, $eventVersion);
    }

    private function key(string $eventName, int $eventVersion): string
    {
        return $eventName . ':' . $eventVersion;
    }

    private function unknown(string $eventName, int $eventVersion): InvalidArgumentException
    {
        return new InvalidArgumentException(
            \sprintf('No domain event is registered for "%s" (v%d).', $eventName, $eventVersion),
        );
    }
}
