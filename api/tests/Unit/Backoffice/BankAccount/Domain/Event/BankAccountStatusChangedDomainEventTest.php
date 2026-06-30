<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\BankAccount\Domain\Event;

use DateTimeImmutable;
use Erpify\Backoffice\BankAccount\Domain\Event\BankAccountStatusChangedDomainEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(BankAccountStatusChangedDomainEvent::class)]
final class BankAccountStatusChangedDomainEventTest extends TestCase
{
    private const string ACCOUNT_ID = '33333333-3333-7000-8000-000000000001';

    private const string BANK_ID = '11111111-1111-7000-8000-000000000001';

    private const string EVENT_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5d';

    private const string OCCURRED_ON = '2026-06-07T12:30:00+00:00';

    #[Test]
    public function itExposesItsStableIdentityAndPiiFreePayload(): void
    {
        $event = $this->event();

        $this->assertSame('erpify.backoffice.bankaccount.status_changed', $event::eventName());
        $this->assertSame('Backoffice.BankAccount', $event::aggregateType());
        $this->assertSame(self::ACCOUNT_ID, $event->aggregateId());
        $this->assertSame(self::BANK_ID, $event->bankId());
        $this->assertSame(
            ['bankId' => self::BANK_ID, 'fromStatus' => 'ACTIVE', 'toStatus' => 'CLOSED'],
            $event->toPrimitives(),
        );
    }

    #[Test]
    public function fromPrimitivesReconstructsAnIdenticalEvent(): void
    {
        $event = $this->event();

        $reconstructed = BankAccountStatusChangedDomainEvent::fromPrimitives(
            self::ACCOUNT_ID,
            $event->toPrimitives(),
            self::EVENT_ID,
            self::OCCURRED_ON,
        );

        $this->assertSame($event->aggregateId(), $reconstructed->aggregateId());
        $this->assertSame($event->bankId(), $reconstructed->bankId());
        $this->assertSame('ACTIVE', $reconstructed->fromStatus());
        $this->assertSame('CLOSED', $reconstructed->toStatus());
        $this->assertSame($event->occurredOn()->format('c'), $reconstructed->occurredOn()->format('c'));
    }

    private function event(): BankAccountStatusChangedDomainEvent
    {
        return new BankAccountStatusChangedDomainEvent(
            self::ACCOUNT_ID,
            self::BANK_ID,
            'ACTIVE',
            'CLOSED',
            self::EVENT_ID,
            new DateTimeImmutable(self::OCCURRED_ON),
        );
    }
}
