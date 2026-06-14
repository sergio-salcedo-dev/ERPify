<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\Bank\Domain\Entity;

use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Backoffice\Bank\Domain\Event\BankCreatedDomainEvent;
use Erpify\Backoffice\Bank\Domain\Event\BankUpdatedDomainEvent;
use Erpify\Shared\Storage\Domain\StoredObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(Bank::class)]
final class BankTest extends TestCase
{
    private const string BANK_ID = '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c';

    public function testCreateRecordsCreatedEventWhoseAggregateIdEqualsTheEntityId(): void
    {
        // Domain-level invariant: the create event's aggregate id is the entity id. (The persist-time
        // divergence the fix removes is locked separately by IdentifiableAssignedIdentifierTest, which
        // asserts Doctrine no longer overwrites this id at flush.)
        $id = self::BANK_ID;

        $bank = Bank::create($id, 'Acme Savings', 'ACME');

        $this->assertSame($id, $bank->getId());

        $events = $bank->pullDomainEvents();
        $this->assertCount(1, $events);

        $event = $events[0];
        $this->assertInstanceOf(BankCreatedDomainEvent::class, $event);
        $this->assertSame($bank->getId(), $event->aggregateId());
    }

    public function testGetStoredObjectReturnsNullWhenNoImageIsStored(): void
    {
        $bank = Bank::create(self::BANK_ID, 'Acme Savings', 'ACME');

        $this->assertNotInstanceOf(StoredObject::class, $bank->getStoredObject());
    }

    public function testGetStoredObjectReturnsTheValueObjectWhenAnImageIsStored(): void
    {
        $storedObject = new StoredObject('object/key', 'image/webp', 1024, 'abc123');

        $bank = Bank::create(self::BANK_ID, 'Acme Savings', 'ACME', null, $storedObject);

        $this->assertSame($storedObject, $bank->getStoredObject());
    }

    public function testRenameRecordsUpdatedEventWhoseAggregateIdEqualsTheEntityId(): void
    {
        $bank = Bank::create(self::BANK_ID, 'Acme Savings', 'ACME');
        $bank->pullDomainEvents(); // drain the creation event

        $bank->rename('Acme Renamed', 'ACME');

        $events = $bank->pullDomainEvents();
        $this->assertCount(1, $events);

        $event = $events[0];
        $this->assertInstanceOf(BankUpdatedDomainEvent::class, $event);
        $this->assertSame(self::BANK_ID, $event->aggregateId());
    }
}
