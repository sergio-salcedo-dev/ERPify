<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\Bank\Domain\Event;

use DateTimeImmutable;
use Erpify\Backoffice\Bank\Domain\Event\BankSnapshot;
use Erpify\Backoffice\Bank\Domain\Event\BankUpdatedDomainEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(BankUpdatedDomainEvent::class)]
final class BankUpdatedDomainEventTest extends TestCase
{
    private const string BANK_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b';

    private const string EVENT_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5d';

    private const string OCCURRED_ON = '2026-06-07T09:15:00+00:00';

    #[Test]
    public function itExposesItsStableIdentityAndAggregate(): void
    {
        $event = $this->event();

        $this->assertSame('erpify.backoffice.bank.updated', $event::eventName());
        $this->assertSame('Backoffice.Bank', $event::aggregateType());
        $this->assertSame(self::BANK_ID, $event->aggregateId());
        $this->assertSame(self::EVENT_ID, $event->eventId());
    }

    #[Test]
    public function itSerializesTheSnapshotAsItsPayload(): void
    {
        $event = $this->event();

        $this->assertSame($this->snapshot()->toPrimitives(), $event->toPrimitives());
    }

    #[Test]
    public function fromPrimitivesReconstructsAnIdenticalEvent(): void
    {
        $event = $this->event();

        $reconstructed = BankUpdatedDomainEvent::fromPrimitives(
            self::BANK_ID,
            $event->toPrimitives(),
            self::EVENT_ID,
            self::OCCURRED_ON,
        );

        $this->assertSame($event->aggregateId(), $reconstructed->aggregateId());
        $this->assertSame($event->eventId(), $reconstructed->eventId());
        $this->assertSame($event->toPrimitives(), $reconstructed->toPrimitives());
        $this->assertSame($event->occurredOn()->format('c'), $reconstructed->occurredOn()->format('c'));
    }

    private function event(): BankUpdatedDomainEvent
    {
        return new BankUpdatedDomainEvent(
            self::BANK_ID,
            $this->snapshot(),
            self::EVENT_ID,
            new DateTimeImmutable(self::OCCURRED_ON),
        );
    }

    private function snapshot(): BankSnapshot
    {
        return new BankSnapshot('Acme Savings', 'ACME', self::OCCURRED_ON, self::OCCURRED_ON);
    }
}
