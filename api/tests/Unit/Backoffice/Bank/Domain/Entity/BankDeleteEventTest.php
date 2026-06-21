<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\Bank\Domain\Entity;

use Erpify\Backoffice\Bank\Domain\Event\BankDeletedDomainEvent;
use Erpify\Tests\Unit\Backoffice\Bank\Domain\Entity\Mother\BankMother;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversNothing]
final class BankDeleteEventTest extends TestCase
{
    public function testDeleteRecordsBankDeletedDomainEventForThisAggregate(): void
    {
        // drained() returns a bank with its creation event already pulled, so only the deletion surfaces.
        $bank = BankMother::drained();

        $bank->delete();

        $events = $bank->pullDomainEvents();

        $this->assertCount(1, $events);
        $event = $events[0];
        $this->assertInstanceOf(BankDeletedDomainEvent::class, $event);
        $this->assertSame(BankMother::DEFAULT_ID, $event->aggregateId());
        $this->assertSame('erpify.backoffice.bank.deleted', $event::eventName());
        // The deletion carries no snapshot; its identity is the aggregate id on the envelope.
        $this->assertSame([], $event->toPrimitives());
    }
}
