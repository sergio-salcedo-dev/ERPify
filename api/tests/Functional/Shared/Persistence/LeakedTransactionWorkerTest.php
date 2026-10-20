<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Persistence;

use Doctrine\DBAL\Connection;
use Erpify\Shared\Persistence\Infrastructure\MessageTransactionBoundaryListener;
use Erpify\Tests\Functional\Shared\Persistence\Fixtures\CallbackMessageBus;
use Erpify\Tests\Functional\Shared\Persistence\Fixtures\LeakProbeMessage;
use Erpify\Tests\Functional\Shared\Persistence\Fixtures\LeaksATransaction;
use Erpify\Tests\Functional\Shared\Persistence\Fixtures\ProbesATransactionScopedLock;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\Connection as DoctrineTransportConnection;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Worker;

/**
 * Drives the worker boundary through a real `Worker` over the Doctrine transport, on the container's event
 * dispatcher: a handler that leaks has its transaction rolled back after the ack, so the ack's `DELETE` rolls back
 * with it, the message stays queued, and it is handled again once its redelivery timeout has passed — once.
 *
 * The test's queue is its own, and the redelivery timeout is passed by moving `delivered_at` back from a second
 * connection rather than by waiting, because the transport reads the wall clock.
 *
 * @internal
 */
#[CoversClass(MessageTransactionBoundaryListener::class)]
final class LeakedTransactionWorkerTest extends KernelTestCase
{
    use ProbesATransactionScopedLock;

    private const string QUEUE = 'leaked_transaction_probe';

    private DoctrineTransport $transport;

    private int $handled = 0;

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        $this->assertInstanceOf(Connection::class, $connection);
        $this->openBothConnections($connection);
        $this->transport = new DoctrineTransport(
            new DoctrineTransportConnection(
                ['table_name' => 'messenger_messages', 'queue_name' => self::QUEUE, 'auto_setup' => false],
                $connection,
            ),
            new PhpSerializer(),
        );
    }

    protected function tearDown(): void
    {
        // Rolled back first: a failing case can leave the queued row locked by the leak it failed to contain.
        $this->closeBothConnections();
        $this->connection->executeStatement('DELETE FROM messenger_messages WHERE queue_name = ?', [self::QUEUE]);
        parent::tearDown();
    }

    #[Test]
    public function aHandlerThatLeaksHasItsAckRolledBackAndItsMessageRedeliveredOnce(): void
    {
        $this->transport->send(new Envelope(new LeakProbeMessage()));

        $this->runTheWorkerOnce();

        $this->assertSame(1, $this->handled);
        $this->assertSame(0, $this->connection->getTransactionNestingLevel());
        $this->assertTrue($this->lockIsFreeOutside(), "the handler's leaked transaction still holds its lock");
        $this->assertSame(1, $this->queued(), "the ack committed although the handler's work never did");
        $this->assertSame([], [...$this->transport->get()], 'redelivered before its redelivery timeout');

        $this->outside->executeStatement(
            "UPDATE messenger_messages SET delivered_at = delivered_at - INTERVAL '2 hours' WHERE queue_name = ?",
            [self::QUEUE],
        );
        $this->runTheWorkerOnce();

        $this->assertSame(2, $this->handled);
        $this->assertSame(0, $this->queued(), 'the clean redelivery was not acknowledged');
    }

    private function runTheWorkerOnce(): void
    {
        $dispatcher = self::getContainer()->get('event_dispatcher');
        $this->assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $stop = static fn (WorkerRunningEvent $event) => $event->getWorker()->stop();
        $dispatcher->addListener(WorkerRunningEvent::class, $stop, -4096);

        try {
            $bus = new CallbackMessageBus($this->handleOnce(...));
            $worker = new Worker([self::QUEUE => $this->transport], $bus, $dispatcher);
            $worker->run(['sleep' => 0]);
        } finally {
            $dispatcher->removeListener(WorkerRunningEvent::class, $stop);
        }
    }

    /** The handler: it leaks a transaction the first time it runs, and only then. */
    private function handleOnce(): void
    {
        ++$this->handled;

        if (1 === $this->handled) {
            LeaksATransaction::on($this->connection);
        }
    }

    private function queued(): int
    {
        $count = $this->outside->fetchOne(
            'SELECT COUNT(*) FROM messenger_messages WHERE queue_name = ?',
            [self::QUEUE],
        );
        $this->assertIsNumeric($count);

        return (int) $count;
    }
}
