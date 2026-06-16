<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Behat\Support;

use DateTimeInterface;
use Erpify\Backoffice\Bank\Domain\Event\BankCreatedDomainEvent;
use Erpify\Backoffice\Bank\Domain\Event\BankDeletedDomainEvent;
use Erpify\Tests\Behat\Support\EventHydrator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(EventHydrator::class)]
final class EventHydratorTest extends TestCase
{
    private const string BANK_ID = '01970000-0000-7000-8000-000000000001';

    private const string OCCURRED_ON = '2026-06-16T10:00:00+00:00';

    #[Test]
    public function itHydratesABankCreatedEventMappingRequiredFieldsAndDefaultingAbsentOptionals(): void
    {
        $event = (new EventHydrator())->hydrate(BankCreatedDomainEvent::class, $this->bankCreatedPayload());

        $this->assertInstanceOf(BankCreatedDomainEvent::class, $event);
        $this->assertSame(self::BANK_ID, $event->aggregateId());
        // The required fields map across; the four absent logo / stored-object params fall back to null.
        $this->assertSame([
            'bankId' => self::BANK_ID,
            'name' => 'Dispatched Bank',
            'shortName' => 'DB',
            'createdAt' => self::OCCURRED_ON,
            'updatedAt' => self::OCCURRED_ON,
            'logoMediaId' => null,
            'logoContentHash' => null,
            'storedObjectContentHash' => null,
            'storedObjectMimeType' => null,
        ], $event->toPrimitives());
    }

    #[Test]
    public function itCastsTheOccurredOnStringToADateTimeImmutable(): void
    {
        $event = (new EventHydrator())->hydrate(BankCreatedDomainEvent::class, $this->bankCreatedPayload());

        $this->assertSame(self::OCCURRED_ON, $event->occurredOn()->format(DateTimeInterface::ATOM));
    }

    #[Test]
    public function itThrowsWhenARequiredParameterIsMissing(): void
    {
        $this->expectException(InvalidArgumentException::class);

        // `occurredOn` is required (no default) and is omitted from `toPrimitives()`.
        $payload = $this->bankCreatedPayload();
        unset($payload['occurredOn']);

        (new EventHydrator())->hydrate(BankCreatedDomainEvent::class, $payload);
    }

    #[Test]
    public function itThrowsWhenTheClassDoesNotExist(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new EventHydrator())->hydrate('Erpify\Does\Not\Exist', []);
    }

    #[Test]
    public function itHydratesABankDeletedEventKeyedOnItsAggregateIdConstructorParameter(): void
    {
        // `BankDeletedDomainEvent` extends `DomainEvent` directly, so its constructor parameter is
        // `aggregateId`, even though `toPrimitives()` exposes the value under the `bankId` key.
        $event = (new EventHydrator())->hydrate(BankDeletedDomainEvent::class, [
            'aggregateId' => self::BANK_ID,
            'occurredOn' => self::OCCURRED_ON,
        ]);

        $this->assertInstanceOf(BankDeletedDomainEvent::class, $event);
        $this->assertSame(self::BANK_ID, $event->aggregateId());
        $this->assertSame(['bankId' => self::BANK_ID], $event->toPrimitives());
    }

    /**
     * @return array<string, string>
     */
    private function bankCreatedPayload(): array
    {
        return [
            'bankId' => self::BANK_ID,
            'occurredOn' => self::OCCURRED_ON,
            'name' => 'Dispatched Bank',
            'shortName' => 'DB',
            'createdAt' => self::OCCURRED_ON,
            'updatedAt' => self::OCCURRED_ON,
        ];
    }
}
