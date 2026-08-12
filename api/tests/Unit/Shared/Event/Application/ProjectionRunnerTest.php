<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Event\Application;

use Erpify\Shared\Event\Application\DomainEventDeserializer;
use Erpify\Shared\Event\Application\EventStore;
use Erpify\Shared\Event\Application\ProjectionCheckpointStore;
use Erpify\Shared\Event\Application\ProjectionRunner;
use Erpify\Shared\Event\Application\Projector;
use Erpify\Shared\Event\Application\StoredEvent;
use Erpify\Shared\Event\Domain\DomainEvent;
use Erpify\Tests\Unit\Shared\Persistence\Double\ImmediateTransactionManager;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ProjectionRunner::class)]
final class ProjectionRunnerTest extends TestCase
{
    private const string NAME = 'bank_count';

    private const array NAMES = ['erpify.backoffice.bank.created'];

    #[Test]
    public function catchUpAppliesStreamedEventsAndAdvancesTheCheckpointToTheLastSequence(): void
    {
        $projector = $this->createMock(Projector::class);
        $projector->method('name')->willReturn(self::NAME);
        $projector->method('subscribedTo')->willReturn(self::NAMES);
        $projector->expects($this->exactly(2))->method('project');

        $eventStore = $this->createMock(EventStore::class);
        $eventStore->expects($this->once())
            ->method('stream')
            ->with(0, self::NAMES, 500)
            ->willReturn([$this->storedEvent(5), $this->storedEvent(9)])
        ;

        $checkpointStore = $this->createMock(ProjectionCheckpointStore::class);
        $checkpointStore->method('lockAndRead')->willReturn(0);
        $checkpointStore->expects($this->once())->method('advance')->with(self::NAME, 9);

        $deserializer = $this->createStub(DomainEventDeserializer::class);
        $deserializer->method('deserialize')->willReturn($this->createStub(DomainEvent::class));

        $this->runner($eventStore, $deserializer, $checkpointStore, new ImmediateTransactionManager(), [$projector])
            ->catchUp(self::NAME)
        ;
    }

    #[Test]
    public function catchUpDoesNotAdvanceTheCheckpointWhenNoEventsAreStreamed(): void
    {
        $projector = $this->createMock(Projector::class);
        $projector->method('name')->willReturn(self::NAME);
        $projector->method('subscribedTo')->willReturn(self::NAMES);
        $projector->expects($this->never())->method('project');

        $checkpointStore = $this->createMock(ProjectionCheckpointStore::class);
        $checkpointStore->method('lockAndRead')->willReturn(3);
        $checkpointStore->expects($this->never())->method('advance');

        $this->runner(
            $this->eventStoreStub(),
            $this->createStub(DomainEventDeserializer::class),
            $checkpointStore,
            new ImmediateTransactionManager(),
            [$projector],
        )->catchUp(self::NAME);
    }

    #[Test]
    public function catchUpAllCatchesUpEveryRegisteredProjector(): void
    {
        $transactions = new ImmediateTransactionManager();

        $this->runner(
            $this->eventStoreStub(),
            $this->createStub(DomainEventDeserializer::class),
            $this->checkpointStoreStub(),
            $transactions,
            [$this->projectorStub('first'), $this->projectorStub('second')],
        )->catchUpAll();

        // The count is the guarantee, not an implementation detail: one boundary per projector means a
        // failure part-way leaves the earlier checkpoints committed and the next run resumes from them.
        // A single boundary around the fan-out would still catch both projectors up and still pass every
        // other assertion here, while turning the run into an all-or-nothing batch.
        $this->assertSame(2, $transactions->opened);
        $this->assertSame(2, $transactions->committed);
    }

    #[Test]
    public function rebuildResetsTheCheckpointAndTheReadModelAndReplaysFromZero(): void
    {
        $projector = $this->createMock(Projector::class);
        $projector->method('name')->willReturn(self::NAME);
        $projector->method('subscribedTo')->willReturn(self::NAMES);
        $projector->expects($this->once())->method('reset');

        $checkpointStore = $this->createMock(ProjectionCheckpointStore::class);
        $checkpointStore->expects($this->once())->method('reset')->with(self::NAME);

        $this->runner(
            $this->eventStoreStub(),
            $this->createStub(DomainEventDeserializer::class),
            $checkpointStore,
            new ImmediateTransactionManager(),
            [$projector],
        )->rebuild(self::NAME);
    }

    #[Test]
    public function rebuildAllRebuildsEveryRegisteredProjector(): void
    {
        $checkpointStore = $this->createMock(ProjectionCheckpointStore::class);
        $checkpointStore->expects($this->exactly(2))->method('reset');

        $this->runner(
            $this->eventStoreStub(),
            $this->createStub(DomainEventDeserializer::class),
            $checkpointStore,
            new ImmediateTransactionManager(),
            [$this->projectorStub('first'), $this->projectorStub('second')],
        )->rebuildAll();
    }

    #[Test]
    public function catchUpThrowsForAnUnknownProjector(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('No projector is registered under "missing".');

        $this->runner(
            $this->eventStoreStub(),
            $this->createStub(DomainEventDeserializer::class),
            $this->checkpointStoreStub(),
            new ImmediateTransactionManager(),
            [$this->projectorStub(self::NAME)],
        )->catchUp('missing');
    }

    /**
     * @param list<Projector> $projectors
     */
    private function runner(
        EventStore $eventStore,
        DomainEventDeserializer $deserializer,
        ProjectionCheckpointStore $checkpointStore,
        ImmediateTransactionManager $transactions,
        array $projectors,
    ): ProjectionRunner {
        return new ProjectionRunner($eventStore, $deserializer, $checkpointStore, $transactions, $projectors);
    }

    private function eventStoreStub(): EventStore
    {
        $eventStore = $this->createStub(EventStore::class);
        $eventStore->method('stream')->willReturn([]);

        return $eventStore;
    }

    private function checkpointStoreStub(): ProjectionCheckpointStore
    {
        $checkpointStore = $this->createStub(ProjectionCheckpointStore::class);
        $checkpointStore->method('lockAndRead')->willReturn(0);

        return $checkpointStore;
    }

    private function projectorStub(string $name): Projector
    {
        $projector = $this->createStub(Projector::class);
        $projector->method('name')->willReturn($name);
        $projector->method('subscribedTo')->willReturn([]);

        return $projector;
    }

    private function storedEvent(int $sequence): StoredEvent
    {
        return new StoredEvent(
            $sequence,
            'event-' . $sequence,
            'aggregate-id',
            'Backoffice.Bank',
            1,
            'erpify.backoffice.bank.created',
            1,
            [],
            [],
            null,
            '2026-06-06T00:00:00+00:00',
            '2026-06-06T00:00:01+00:00',
        );
    }
}
