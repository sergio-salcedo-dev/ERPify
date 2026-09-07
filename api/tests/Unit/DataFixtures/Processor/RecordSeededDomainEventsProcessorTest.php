<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\DataFixtures\Processor;

use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Tests\DataFixtures\Processor\RecordSeededDomainEventsProcessor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * A fixture aggregate is built through its domain factory, so it records the same domain events an
 * application-created one does — and persisting it with the ORM drops them on the floor. This
 * processor is what keeps the seeded event log in step with the seeded rows.
 *
 * @internal
 */
#[CoversClass(RecordSeededDomainEventsProcessor::class)]
final class RecordSeededDomainEventsProcessorTest extends TestCase
{
    public function testItAppendsEveryEventTheSeededAggregateRecorded(): void
    {
        $eventStore = new CollectingEventStore();
        $processor = new RecordSeededDomainEventsProcessor($eventStore);

        $processor->postProcess('bank_acme', Bank::create('11111111-1111-7000-8000-0000000000ff', 'Acme Bank', 'ACM'));

        $this->assertCount(1, $eventStore->appended);
        $this->assertSame('erpify.backoffice.bank.created', $eventStore->appended[0]::eventName());
        $this->assertSame('11111111-1111-7000-8000-0000000000ff', $eventStore->appended[0]->aggregateId());
    }

    public function testItIgnoresAFixtureObjectThatIsNotAnAggregateRoot(): void
    {
        $eventStore = new CollectingEventStore();
        $processor = new RecordSeededDomainEventsProcessor($eventStore);

        $processor->postProcess('some_plain_object', new stdClass());

        $this->assertSame([], $eventStore->appended);
    }

    /**
     * The buffer is drained, not read: `pullDomainEvents()` clears the aggregate. Pinned because the
     * loader is free to hand the same object back — a processor that peeked instead of pulling would
     * append the same creation twice and the projected total would drift above the row count.
     */
    public function testItDrainsTheAggregateSoASecondPassAppendsNothing(): void
    {
        $eventStore = new CollectingEventStore();
        $processor = new RecordSeededDomainEventsProcessor($eventStore);
        $bank = Bank::create('11111111-1111-7000-8000-0000000000fe', 'Beta Bank', 'BET');

        $processor->postProcess('bank_beta', $bank);
        $processor->postProcess('bank_beta', $bank);

        $this->assertCount(1, $eventStore->appended);
    }

    /**
     * `preProcess` runs before the row exists. Appending there would put the event in the log ahead of
     * the row it describes, and a load that failed afterwards would leave the log claiming a creation
     * that never landed.
     */
    public function testItAppendsNothingBeforeTheRowIsPersisted(): void
    {
        $eventStore = new CollectingEventStore();
        $processor = new RecordSeededDomainEventsProcessor($eventStore);

        $processor->preProcess('bank_gamma', Bank::create('11111111-1111-7000-8000-0000000000fd', 'Gamma Bank', 'GAM'));

        $this->assertSame([], $eventStore->appended);
    }
}
