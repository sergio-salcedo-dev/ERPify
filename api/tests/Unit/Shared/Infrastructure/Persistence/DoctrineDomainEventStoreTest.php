<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Infrastructure\Persistence;

use DateTimeImmutable;
use Erpify\Shared\Application\Validation\Validator;
use Erpify\Shared\Domain\Event\DomainEvent;
use Erpify\Shared\Infrastructure\Persistence\DoctrineDomainEventStore;
use Erpify\Shared\Infrastructure\Persistence\Entity\StoredDomainEvent;
use Erpify\Shared\Infrastructure\Persistence\StoredDomainEventRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * @internal
 */
#[CoversClass(DoctrineDomainEventStore::class)]
final class DoctrineDomainEventStoreTest extends TestCase
{
    public function testAppendPersistsEventWithAnAppGeneratedV7Id(): void
    {
        $captured = null;
        $repository = $this->createStub(StoredDomainEventRepository::class);
        $repository->method('save')->willReturnCallback(
            static function (StoredDomainEvent $storedDomainEvent) use (&$captured): void {
                $captured = $storedDomainEvent;
            },
        );

        $store = new DoctrineDomainEventStore($repository, $this->noopValidator());

        $store->append($this->domainEvent('aggregate-id', 'event-id'));

        $this->assertInstanceOf(StoredDomainEvent::class, $captured);

        // The id is no longer Doctrine-generated; the store must mint a non-null v7 id before persist.
        $id = $captured->getId();
        $this->assertIsString($id);
        $this->assertInstanceOf(UuidV7::class, Uuid::fromString($id));

        $this->assertSame('aggregate-id', $captured->aggregateId());
        $this->assertSame('event-id', $captured->eventId());
        $this->assertSame('test.event.occurred', $captured->name());
    }

    private function noopValidator(): Validator
    {
        $inner = $this->createStub(ValidatorInterface::class);
        $inner->method('validate')->willReturn(new ConstraintViolationList());

        return new Validator($inner);
    }

    private function domainEvent(string $aggregateId, string $eventId): DomainEvent
    {
        return new class ($aggregateId, $eventId, new DateTimeImmutable()) extends DomainEvent {
            #[\Override]
            public static function eventName(): string
            {
                return 'test.event.occurred';
            }

            /**
             * @return array<string, mixed>
             */
            #[\Override]
            public function toPrimitives(): array
            {
                return ['aggregateId' => $this->aggregateId()];
            }
        };
    }
}
