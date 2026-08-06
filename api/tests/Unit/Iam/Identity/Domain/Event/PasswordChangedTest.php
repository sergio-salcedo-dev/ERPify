<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Domain\Event;

use DateTimeImmutable;
use Erpify\Iam\Identity\Domain\Event\PasswordChanged;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(PasswordChanged::class)]
final class PasswordChangedTest extends TestCase
{
    private const string AGGREGATE_ID = '0190ffff-aaaa-7bbb-8ccc-0d1e2f3a4b5c';

    private const string EVENT_ID = '0190ffff-aaaa-7bbb-8ccc-0d1e2f3a4b5d';

    private const string OCCURRED_ON = '2026-08-04T12:00:00+00:00';

    public function testItsNameIsTheWireContract(): void
    {
        $this->assertSame('erpify.iam.identity.password-changed', PasswordChanged::eventName());
    }

    /**
     * The registry classifies this type as a person aggregate, which is what keeps the event off every
     * persisted transport. The string is the join between the event and `api/.persistent-transport-policy`,
     * so changing it silently would move the event out from under that classification.
     */
    public function testItsAggregateTypeIsTheKeyThePersistentTransportPolicyClassifies(): void
    {
        $this->assertSame('Iam.Identity', PasswordChanged::aggregateType());
    }

    /**
     * The payload is empty by design: the aggregate id is a person's id and is already the whole subject, so
     * carrying anything else would put a second personal datum in the durable log for no reader.
     */
    public function testItCarriesNoPayload(): void
    {
        $this->assertSame([], $this->event()->toPrimitives());
    }

    public function testItRebuildsFromItsPrimitivesUnchanged(): void
    {
        $rebuilt = PasswordChanged::fromPrimitives(
            self::AGGREGATE_ID,
            [],
            self::EVENT_ID,
            self::OCCURRED_ON,
        );

        $this->assertSame(self::AGGREGATE_ID, $rebuilt->aggregateId());
        $this->assertSame(self::EVENT_ID, $rebuilt->eventId());
        $this->assertSame(self::OCCURRED_ON, $rebuilt->occurredOn()->format(DateTimeImmutable::ATOM));
        $this->assertSame([], $rebuilt->toPrimitives());
    }

    private function event(): PasswordChanged
    {
        return new PasswordChanged(
            self::AGGREGATE_ID,
            self::EVENT_ID,
            new DateTimeImmutable(self::OCCURRED_ON),
        );
    }
}
