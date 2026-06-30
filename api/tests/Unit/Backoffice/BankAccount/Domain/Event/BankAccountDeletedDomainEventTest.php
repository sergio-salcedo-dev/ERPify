<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\BankAccount\Domain\Event;

use DateTimeImmutable;
use Erpify\Backoffice\BankAccount\Domain\Event\BankAccountDeletedDomainEvent;
use Erpify\Backoffice\BankAccount\Domain\Event\BankAccountSnapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(BankAccountDeletedDomainEvent::class)]
final class BankAccountDeletedDomainEventTest extends TestCase
{
    private const string ACCOUNT_ID = '33333333-3333-7000-8000-000000000001';

    private const string BANK_ID = '11111111-1111-7000-8000-000000000001';

    private const string EVENT_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5e';

    private const string OCCURRED_ON = '2026-06-08T18:00:00+00:00';

    #[Test]
    public function itExposesItsStableIdentityAndCarriesTheOwningBankId(): void
    {
        $event = $this->event();

        $this->assertSame('erpify.backoffice.bankaccount.deleted', $event::eventName());
        $this->assertSame('Backoffice.BankAccount', $event::aggregateType());
        $this->assertSame(self::ACCOUNT_ID, $event->aggregateId());
        $this->assertSame(self::BANK_ID, $event->bankId());
        $this->assertSame(self::EVENT_ID, $event->eventId());
    }

    #[Test]
    public function fromPrimitivesReconstructsAnIdenticalEvent(): void
    {
        $event = $this->event();

        $reconstructed = BankAccountDeletedDomainEvent::fromPrimitives(
            self::ACCOUNT_ID,
            $event->toPrimitives(),
            self::EVENT_ID,
            self::OCCURRED_ON,
        );

        $this->assertSame($event->aggregateId(), $reconstructed->aggregateId());
        $this->assertSame($event->bankId(), $reconstructed->bankId());
        $this->assertSame($event->toPrimitives(), $reconstructed->toPrimitives());
        $this->assertSame($event->occurredOn()->format('c'), $reconstructed->occurredOn()->format('c'));
    }

    private function event(): BankAccountDeletedDomainEvent
    {
        return new BankAccountDeletedDomainEvent(
            self::ACCOUNT_ID,
            new BankAccountSnapshot(self::BANK_ID, 'CLOSED', self::OCCURRED_ON, self::OCCURRED_ON),
            self::EVENT_ID,
            new DateTimeImmutable(self::OCCURRED_ON),
        );
    }
}
