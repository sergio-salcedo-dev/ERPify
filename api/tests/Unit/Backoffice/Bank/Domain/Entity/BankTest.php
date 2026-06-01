<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\Bank\Domain\Entity;

use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Backoffice\Bank\Domain\Event\BankCreatedDomainEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(Bank::class)]
final class BankTest extends TestCase
{
    public function testCreateRecordsCreatedEventWhoseIdEqualsTheEntityId(): void
    {
        // Domain-level invariant: the create event carries the same id as the entity. (The persist-time
        // divergence the fix removes is locked separately by IdentifiableAssignedIdentifierTest, which
        // asserts Doctrine no longer overwrites this id at flush.)
        $id = '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c';

        $bank = Bank::create($id, '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2d', 'Acme Savings', 'ACME');

        $this->assertSame($id, $bank->getId());

        $events = $bank->pullDomainEvents();
        $this->assertCount(1, $events);

        $event = $events[0];
        $this->assertInstanceOf(BankCreatedDomainEvent::class, $event);
        $this->assertSame($bank->getId(), $event->aggregateId());
    }
}
