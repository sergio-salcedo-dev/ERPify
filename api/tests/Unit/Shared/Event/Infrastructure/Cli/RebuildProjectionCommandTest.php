<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Event\Infrastructure\Cli;

use Erpify\Shared\Event\Application\DomainEventDeserializer;
use Erpify\Shared\Event\Application\EventStore;
use Erpify\Shared\Event\Application\ProjectionCheckpointStore;
use Erpify\Shared\Event\Application\ProjectionRunner;
use Erpify\Shared\Event\Application\Projector;
use Erpify\Shared\Event\Infrastructure\Cli\RebuildProjectionCommand;
use Erpify\Tests\Unit\Shared\Persistence\Double\ImmediateTransactionManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The runner is final, so it is exercised as a real instance over mocked ports; the command's effect
 * is asserted at the checkpoint store (a rebuild resets it).
 *
 * @internal
 */
#[CoversClass(RebuildProjectionCommand::class)]
final class RebuildProjectionCommandTest extends TestCase
{
    private const string PROJECTION = 'bank_count';

    private MockObject&ProjectionCheckpointStore $checkpointStore;

    private RebuildProjectionCommand $command;

    protected function setUp(): void
    {
        $this->checkpointStore = $this->createMock(ProjectionCheckpointStore::class);

        $eventStore = $this->createStub(EventStore::class);
        $eventStore->method('stream')->willReturn([]);

        $projector = $this->createStub(Projector::class);
        $projector->method('name')->willReturn(self::PROJECTION);
        $projector->method('subscribedTo')->willReturn([]);

        $runner = new ProjectionRunner(
            $eventStore,
            $this->createStub(DomainEventDeserializer::class),
            $this->checkpointStore,
            new ImmediateTransactionManager(),
            [$projector],
        );

        $this->command = new RebuildProjectionCommand($runner);
    }

    #[Test]
    public function itRebuildsASingleProjectionByName(): void
    {
        $this->checkpointStore->expects($this->once())->method('reset')->with(self::PROJECTION);

        $tester = new CommandTester($this->command);
        $tester->execute(['name' => self::PROJECTION]);

        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString(self::PROJECTION, $tester->getDisplay());
    }

    #[Test]
    public function itRebuildsEveryProjectionWithTheAllOption(): void
    {
        $this->checkpointStore->expects($this->once())->method('reset')->with(self::PROJECTION);

        $tester = new CommandTester($this->command);
        $tester->execute(['--all' => true]);

        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('All projections rebuilt', $tester->getDisplay());
    }

    #[Test]
    public function itFailsWhenNeitherANameNorAllIsGiven(): void
    {
        $this->checkpointStore->expects($this->never())->method('reset');

        $tester = new CommandTester($this->command);
        $tester->execute([]);

        $this->assertSame(Command::INVALID, $tester->getStatusCode());
        $this->assertStringContainsString('Provide a projection name', $tester->getDisplay());
    }
}
