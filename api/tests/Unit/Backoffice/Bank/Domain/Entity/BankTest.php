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
use Erpify\Tests\Unit\Backoffice\Bank\Domain\Entity\Mother\BankMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * @internal
 */
#[CoversClass(Bank::class)]
final class BankTest extends TestCase
{
    protected function tearDown(): void
    {
        SystemClock::reset();

        parent::tearDown();
    }

    public function testCreateRecordsCreatedEventWhoseAggregateIdEqualsTheEntityId(): void
    {
        // Domain-level invariant: the create event's aggregate id is the entity id. Persist-time id
        // stability is locked separately by IdentifiableAssignedIdentifierTest, which asserts Doctrine
        // does not overwrite this id at flush.
        $bank = BankMother::create();

        $this->assertSame(BankMother::DEFAULT_ID, $bank->getId());

        $events = $bank->pullDomainEvents();
        $this->assertCount(1, $events);

        $event = $events[0];
        $this->assertInstanceOf(BankCreatedDomainEvent::class, $event);
        $this->assertSame($bank->getId(), $event->aggregateId());
    }

    public function testGetStoredObjectReturnsNullWhenNoImageIsStored(): void
    {
        $bank = BankMother::create();

        $this->assertNotInstanceOf(StoredObject::class, $bank->getStoredObject());
    }

    public function testGetStoredObjectReturnsTheValueObjectWhenAnImageIsStored(): void
    {
        $storedObject = new StoredObject('object/key', 'image/webp', 1024, 'abc123');

        $bank = BankMother::create(storedObject: $storedObject);

        $this->assertSame($storedObject, $bank->getStoredObject());
    }

    public function testRenameRecordsUpdatedEventWhoseAggregateIdEqualsTheEntityId(): void
    {
        $bank = BankMother::drained();

        $bank->rename('Acme Renamed', 'ACME');

        $events = $bank->pullDomainEvents();
        $this->assertCount(1, $events);

        $event = $events[0];
        $this->assertInstanceOf(BankUpdatedDomainEvent::class, $event);
        $this->assertSame(BankMother::DEFAULT_ID, $event->aggregateId());
    }

    public function testCreateStampsTimestampsAndEventOccurredOnFromTheAmbientClock(): void
    {
        $instant = '2026-06-14T09:30:00+00:00';
        SystemClock::set(new SymfonyClock(new MockClock($instant)));

        $bank = BankMother::create();

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
        $bank = BankMother::drained();

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
