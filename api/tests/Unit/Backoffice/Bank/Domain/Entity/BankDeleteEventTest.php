<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\Bank\Domain\Entity;

use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Backoffice\Bank\Domain\Event\BankDeletedDomainEvent;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversNothing]
final class BankDeleteEventTest extends TestCase
{
    private const string BANK_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b';

    public function testDeleteRecordsBankDeletedDomainEventForThisAggregate(): void
    {
        $bank = Bank::create(self::BANK_ID, 'Acme Savings', 'ACME');
        // Drain the creation event so we assert only on the deletion.
        $bank->pullDomainEvents();

        $bank->delete();

        $events = $bank->pullDomainEvents();

        $this->assertCount(1, $events);
        $event = $events[0];
        $this->assertInstanceOf(BankDeletedDomainEvent::class, $event);
        $this->assertSame(self::BANK_ID, $event->aggregateId());
        $this->assertSame('erpify.backoffice.bank.deleted', $event::eventName());
        $this->assertSame(['bankId' => self::BANK_ID], $event->toPrimitives());
    }
}
