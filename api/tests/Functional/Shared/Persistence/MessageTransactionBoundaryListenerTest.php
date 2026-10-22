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
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;

/**
 * Pins the worker boundary: a leak is rolled back before a failed message's retry and after a handled message's
 * ack, a carried-over or idle-tick leak is caught outside `test`, and outside `test` it never throws. Every leak
 * case reads a lock from a second connection; where each check is registered is
 * {@see MessageTransactionBoundaryRegistrationTest}'s.
 *
 * @internal
 */
#[CoversClass(MessageTransactionBoundaryListener::class)]
final class MessageTransactionBoundaryListenerTest extends KernelTestCase
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
    public function aHandledMessageThatLeakedIsRolledBackAndNamedButNeverThrows(): void
    {
        $listener = $this->listener('prod');

        $listener->onMessageReceived($this->received());

        $this->connection->beginTransaction();
        $this->takeTheLock();
        $listener->onWorkerRunning();

        $this->assertTrue($this->lockIsFreeOutside(), 'the leaked transaction still holds its lock');
        $this->assertCount(1, $this->logger->records);
        $this->assertSame('critical', $this->logger->records[0]['level']);
        $this->assertSame(stdClass::class, $this->logger->records[0]['context']['message_class'] ?? null);
        $this->assertSame('worker_message', $this->logger->records[0]['context']['boundary'] ?? null);
    }

    #[Test]
    public function aFailedMessageThatLeakedIsRolledBackBeforeItsRetryIsWritten(): void
    {
        $listener = $this->listener('prod');

        $listener->onMessageReceived($this->received());

        $this->connection->beginTransaction();
        $this->takeTheLock();
        $listener->onMessageFailed();

        $this->assertTrue($this->lockIsFreeOutside(), 'the leaked transaction still holds its lock');
        $this->assertSame('worker_message_failed', $this->logger->records[0]['context']['boundary'] ?? null);
    }

    #[Test]
    public function outsideTestALeakCarriedIntoTheNextMessageOrAnIdleTickIsRolledBack(): void
    {
        $listener = $this->listener('prod');

        $this->connection->beginTransaction();
        $this->takeTheLock();
        $listener->onWorkerRunning();
        $this->assertTrue($this->lockIsFreeOutside(), 'an idle tick left the leak open');

        $this->connection->beginTransaction();
        $this->takeTheLock();
        $listener->onMessageReceived($this->received());
        $this->assertTrue($this->lockIsFreeOutside(), 'the next message adopted the leak');

        $boundaries = \array_map(
            static fn (array $record): mixed => $record['context']['boundary'] ?? null,
            $this->logger->records,
        );
        $this->assertSame(['worker_idle', 'worker_message_start'], $boundaries);
    }

    #[Test]
    public function underTestAnIdleTickLeavesATransactionItDidNotSeeOpen(): void
    {
        $listener = $this->listener('test');
        $this->connection->beginTransaction();

        $listener->onWorkerRunning();

        $this->assertSame(1, $this->connection->getTransactionNestingLevel());
        $this->assertSame([], $this->logger->records);
    }

    private function listener(string $environment): MessageTransactionBoundaryListener
    {
        return new MessageTransactionBoundaryListener(
            new LeakedTransactionContainment($this->connection, $this->logger),
            $environment,
        );
    }

    private function received(): WorkerMessageReceivedEvent
    {
        return new WorkerMessageReceivedEvent(new Envelope(new stdClass()), 'async');
    }
}
