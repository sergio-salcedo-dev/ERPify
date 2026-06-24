<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Event\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Erpify\Backoffice\Bank\Domain\Event\BankCreatedDomainEvent;
use Erpify\Backoffice\Bank\Domain\Event\BankSnapshot;
use Erpify\Shared\Event\Application\DomainEventSerializer;
use Erpify\Shared\Event\Domain\Exception\EventStreamConcurrencyConflict;
use Erpify\Shared\Event\Infrastructure\Persistence\DbalEventStore;
use Erpify\Shared\Uuid\Domain\Uuid;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(DbalEventStore::class)]
final class DbalEventStoreConcurrencyTest extends TestCase
{
    public function testTranslatesAStreamUniqueViolationIntoAConcurrencyConflict(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('executeStatement')
            ->willThrowException($this->createStub(UniqueConstraintViolationException::class))
        ;

        $serializer = $this->createStub(DomainEventSerializer::class);
        $serializer->method('serialize')->willReturn(['payload' => [], 'metadata' => []]);

        $store = new DbalEventStore($connection, $serializer);

        $this->expectException(EventStreamConcurrencyConflict::class);

        $store->append($this->anEvent());
    }

    private function anEvent(): BankCreatedDomainEvent
    {
        $now = '2026-01-01T00:00:00+00:00';

        return new BankCreatedDomainEvent(Uuid::generate(), new BankSnapshot('Bank', 'BANK', $now, $now));
    }
}
