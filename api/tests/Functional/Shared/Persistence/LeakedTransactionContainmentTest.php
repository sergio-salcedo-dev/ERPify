<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Persistence;

use Doctrine\DBAL\Connection;
use Erpify\Shared\Persistence\Infrastructure\LeakedTransactionContainment;
use Erpify\Shared\Persistence\Infrastructure\MessageTransactionBoundaryListener;
use Erpify\Tests\Functional\Shared\Persistence\Fixtures\ProbesATransactionScopedLock;
use Erpify\Tests\Functional\Shared\Persistence\Fixtures\RecordingLogger;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use stdClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;

/**
 * Pins what a unit of work that forgot its transaction costs the NEXT one on the same connection: nothing.
 * Every case asserts the consequence through {@see ProbesATransactionScopedLock}, not the nesting counter.
 *
 * @internal
 */
#[CoversClass(LeakedTransactionContainment::class)]
#[CoversClass(MessageTransactionBoundaryListener::class)]
final class LeakedTransactionContainmentTest extends KernelTestCase
{
    use ProbesATransactionScopedLock;

    private RecordingLogger $logger;

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        $this->assertInstanceOf(Connection::class, $connection);
        $this->openBothConnections($connection);
        $this->logger = new RecordingLogger();
    }

    protected function tearDown(): void
    {
        $this->closeBothConnections();
        parent::tearDown();
    }

    #[Test]
    public function aTransactionOpenBeforeTheUnitBeganIsNotTheUnitsToEnd(): void
    {
        $containment = new LeakedTransactionContainment($this->connection, $this->logger);

        $this->connection->beginTransaction();
        $baseline = $containment->nestingLevel();
        $this->connection->beginTransaction();

        $this->assertSame(1, $containment->containAbove($baseline, ['boundary' => 'test']));
        $this->assertSame(1, $this->connection->getTransactionNestingLevel());
    }

    #[Test]
    public function aWorkerMessageThatLeakedIsRolledBackAndNamedButNeverThrows(): void
    {
        $listener = new MessageTransactionBoundaryListener(
            new LeakedTransactionContainment($this->connection, $this->logger),
        );

        $listener->onMessageReceived(new WorkerMessageReceivedEvent(new Envelope(new stdClass()), 'async'));

        $this->connection->beginTransaction();
        $this->takeTheLock();

        $listener->onWorkerRunning();

        $this->assertSame(0, $this->connection->getTransactionNestingLevel());
        $this->assertTrue($this->lockIsFreeOutside(), 'the leaked transaction still holds its lock');
        $this->assertCount(1, $this->logger->records);
        $this->assertSame('critical', $this->logger->records[0]['level']);
        $this->assertSame(stdClass::class, $this->logger->records[0]['context']['message_class'] ?? null);
        $this->assertSame(1, $this->logger->records[0]['context']['leaked_levels'] ?? null);
    }

    #[Test]
    public function anIdleWorkerTickWithNoMessageDoesNothing(): void
    {
        $listener = new MessageTransactionBoundaryListener(
            new LeakedTransactionContainment($this->connection, $this->logger),
        );
        $this->connection->beginTransaction();

        $listener->onWorkerRunning();

        $this->assertSame(1, $this->connection->getTransactionNestingLevel());
        $this->assertSame([], $this->logger->records);
    }

    #[Test]
    public function theWorkerBoundaryRunsAheadOfMessengersServiceReset(): void
    {
        $dispatcher = $this->dispatcher();
        $found = false;

        foreach ($dispatcher->getListeners(WorkerRunningEvent::class) as $listener) {
            if (\is_array($listener) && $listener[0] instanceof MessageTransactionBoundaryListener) {
                $found = true;
                $this->assertGreaterThan(
                    -1024,
                    $dispatcher->getListenerPriority(WorkerRunningEvent::class, $listener),
                );
            }
        }

        $this->assertTrue($found, 'the worker boundary is not registered');
    }

    #[Test]
    public function aRollbackTheServerCannotAnswerFallsBackToAFreshCleanConnection(): void
    {
        $containment = new LeakedTransactionContainment($this->connection, $this->logger);

        $this->connection->beginTransaction();
        $this->takeTheLock();
        $this->connection->beginTransaction();
        $this->connection->setRollbackOnly();

        $pid = $this->connection->fetchOne('SELECT pg_backend_pid()');
        $this->outside->executeStatement('SELECT pg_terminate_backend(:pid)', ['pid' => $pid]);

        $this->assertSame(2, $containment->containAbove(0, ['boundary' => 'test']));

        $this->assertSame(0, $this->connection->getTransactionNestingLevel());
        $this->assertTrue($this->logger->records[0]['context']['connection_closed'] ?? false);
        $this->assertTrue($this->lockIsFreeOutside());
        // The rollback-only flag survived `close()`; the next unit's commit is what it would have broken.
        $this->connection->beginTransaction();
        $this->connection->commit();
    }

    private function dispatcher(): EventDispatcherInterface
    {
        $dispatcher = self::getContainer()->get('event_dispatcher');
        $this->assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        return $dispatcher;
    }
}
