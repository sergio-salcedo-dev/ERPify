<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\Bank\Domain\Entity;

use DateTimeInterface;
use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Backoffice\Bank\Domain\Event\BankCreatedDomainEvent;
use Erpify\Backoffice\Bank\Domain\Event\BankUpdatedDomainEvent;
use Erpify\Shared\Clock\Domain\SystemClock;
use Erpify\Shared\Clock\Infrastructure\SymfonyClock;
use Erpify\Shared\Storage\Domain\StoredObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * @internal
 */
#[CoversClass(Bank::class)]
final class BankTest extends TestCase
{
    private const string BANK_ID = '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c';

    protected function tearDown(): void
    {
        SystemClock::reset();

        parent::tearDown();
    }

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

    public function testCreateStampsTimestampsAndEventOccurredOnFromTheAmbientClock(): void
    {
        $instant = '2026-06-14T09:30:00+00:00';
        SystemClock::set(new SymfonyClock(new MockClock($instant)));

        $bank = Bank::create(self::BANK_ID, 'Acme Savings', 'ACME');

        $this->assertSame($instant, $bank->getCreatedAt()->format(DateTimeInterface::ATOM));
        $this->assertSame($instant, $bank->getUpdatedAt()->format(DateTimeInterface::ATOM));

        $events = $bank->pullDomainEvents();
        $this->assertCount(1, $events);
        $event = $events[0];
        $this->assertInstanceOf(BankCreatedDomainEvent::class, $event);
        $this->assertSame($instant, $event->occurredOn()->format(DateTimeInterface::ATOM));
    }

    public function testRenameStampsUpdatedAtAndEventOccurredOnFromTheAmbientClock(): void
    {
        $createdAt = '2026-06-14T09:30:00+00:00';
        $renamedAt = '2026-06-15T11:00:00+00:00';

        SystemClock::set(new SymfonyClock(new MockClock($createdAt)));
        $bank = Bank::create(self::BANK_ID, 'Acme Savings', 'ACME');
        $bank->pullDomainEvents(); // drain the creation event

        SystemClock::set(new SymfonyClock(new MockClock($renamedAt)));
        $bank->rename('Acme Renamed', 'ACME');

        $this->assertSame($createdAt, $bank->getCreatedAt()->format(DateTimeInterface::ATOM));
        $this->assertSame($renamedAt, $bank->getUpdatedAt()->format(DateTimeInterface::ATOM));

        $events = $bank->pullDomainEvents();
        $this->assertCount(1, $events);
        $event = $events[0];
        $this->assertInstanceOf(BankUpdatedDomainEvent::class, $event);
        $this->assertSame($renamedAt, $event->occurredOn()->format(DateTimeInterface::ATOM));
    }
}
