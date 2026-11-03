<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Event\Infrastructure\Cli;

use Doctrine\DBAL\Connection as DbalConnection;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Shared\Event\Infrastructure\Cli\AssertTransactionalTransportCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\Connection;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

/**
 * Real transport objects, no kernel: the property under test is which object the DSN resolved to and which
 * connection it holds, and a double of either would assert the double.
 *
 * @internal
 */
#[CoversClass(AssertTransactionalTransportCommand::class)]
final class AssertTransactionalTransportCommandTest extends TestCase
{
    #[Test]
    public function itPassesWhenAsyncIsTheDoctrineTransportOnTheApplicationConnection(): void
    {
        $applicationConnection = $this->createStub(DbalConnection::class);

        $tester = $this->execute(
            new ServiceLocator([
                'async' => static fn (): DoctrineTransport => self::doctrineTransportOn($applicationConnection),
            ]),
            $applicationConnection,
        );

        $tester->assertCommandIsSuccessful();
    }

    #[Test]
    public function itFailsWhenAsyncIsNotTheDoctrineTransport(): void
    {
        $tester = $this->execute(
            new ServiceLocator(['async' => static fn (): InMemoryTransport => new InMemoryTransport()]),
            $this->createStub(DbalConnection::class),
        );

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString(InMemoryTransport::class, $tester->getDisplay());
    }

    #[Test]
    public function itFailsWhenTheDoctrineTransportHoldsAnotherConnection(): void
    {
        // Same class, wrong connection: a `doctrine://other` DSN keeps the transport type and still commits
        // the INSERT on its own connection, outside the transaction the use case opened.
        $tester = $this->execute(
            new ServiceLocator([
                'async' => fn (): DoctrineTransport => self::doctrineTransportOn(
                    $this->createStub(DbalConnection::class),
                ),
            ]),
            $this->createStub(DbalConnection::class),
        );

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('different connection', $tester->getDisplay());
    }

    #[Test]
    public function itFailsWhenTheTransportCannotBeBuilt(): void
    {
        // An unsupported DSN scheme throws while the locator builds the service.
        $tester = $this->execute(
            new ServiceLocator(['async' => static function (): never {
                throw new RuntimeException('No transport supports amqp://user:s3cr3t@broker:5672/%2f/messages.');
            }]),
            $this->createStub(DbalConnection::class),
        );

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('cannot be built', $tester->getDisplay());
        $this->assertStringNotContainsString('s3cr3t', $tester->getDisplay());
        $this->assertStringNotContainsString('broker', $tester->getDisplay());
    }

    /**
     * @param ServiceLocator<mixed> $transports
     */
    private function execute(ServiceLocator $transports, DbalConnection $applicationConnection): CommandTester
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($applicationConnection);

        $tester = new CommandTester(new AssertTransactionalTransportCommand($transports, $entityManager));
        $tester->execute([]);

        return $tester;
    }

    private static function doctrineTransportOn(DbalConnection $connection): DoctrineTransport
    {
        return new DoctrineTransport(
            new Connection(['table_name' => 'messenger_messages'], $connection),
            new PhpSerializer(),
        );
    }
}
