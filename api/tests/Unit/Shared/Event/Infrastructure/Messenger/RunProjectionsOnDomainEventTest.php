<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Event\Infrastructure\Messenger;

use Doctrine\ORM\EntityManagerInterface;
use Erpify\Shared\Event\Application\DomainEventDeserializer;
use Erpify\Shared\Event\Application\EventStore;
use Erpify\Shared\Event\Application\ProjectionCheckpointStore;
use Erpify\Shared\Event\Application\ProjectionRunner;
use Erpify\Shared\Event\Application\Projector;
use Erpify\Shared\Event\Infrastructure\Messenger\RunProjectionsOnDomainEvent;
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
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())
            ->method('wrapInTransaction')
            ->willReturnCallback(static fn (callable $work): mixed => $work())
        ;

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
            $entityManager,
            [$projector],
        );

        (new RunProjectionsOnDomainEvent($runner))();
    }
}
