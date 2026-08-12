<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Event\Infrastructure\Messenger;

use Erpify\Shared\Event\Application\DomainEventDeserializer;
use Erpify\Shared\Event\Application\EventStore;
use Erpify\Shared\Event\Application\ProjectionCheckpointStore;
use Erpify\Shared\Event\Application\ProjectionRunner;
use Erpify\Shared\Event\Application\Projector;
use Erpify\Shared\Event\Infrastructure\Messenger\RunProjectionsOnDomainEvent;
use Erpify\Tests\Unit\Shared\Persistence\Double\ImmediateTransactionManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(RunProjectionsOnDomainEvent::class)]
final class RunProjectionsOnDomainEventTest extends TestCase
{
    #[Test]
    public function itCatchesUpEveryProjectionWhenInvoked(): void
    {
        $transactions = new ImmediateTransactionManager();

        $checkpointStore = $this->createStub(ProjectionCheckpointStore::class);
        $checkpointStore->method('lockAndRead')->willReturn(0);

        $eventStore = $this->createStub(EventStore::class);
        $eventStore->method('stream')->willReturn([]);

        $projector = $this->createStub(Projector::class);
        $projector->method('name')->willReturn('bank_count');
        $projector->method('subscribedTo')->willReturn([]);

        $runner = new ProjectionRunner(
            $eventStore,
            $this->createStub(DomainEventDeserializer::class),
            $checkpointStore,
            $transactions,
            [$projector],
        );

        (new RunProjectionsOnDomainEvent($runner))();

        // The reactor is fired once per delivered event, so one boundary per invocation is the whole
        // cost model: a second one here would double the checkpoint locks under live traffic.
        $this->assertSame(1, $transactions->committed);
    }
}
