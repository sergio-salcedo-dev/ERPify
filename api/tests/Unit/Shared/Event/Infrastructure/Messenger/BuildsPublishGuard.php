<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Event\Infrastructure\Messenger;

use Doctrine\DBAL\Connection as DbalConnection;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Shared\Event\Infrastructure\Messenger\AsyncTransportValidator;
use Erpify\Shared\Event\Infrastructure\Messenger\RequireTransactionalPublishMiddleware;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\Connection;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use Symfony\Component\Messenger\Transport\Sender\SendersLocatorInterface;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

/**
 * Real transports behind the real validator, no kernel: what the guard tests assert is which object `async`
 * resolved to and which connection it holds, and a double of either would assert the double.
 *
 * @phpstan-require-extends \PHPUnit\Framework\TestCase
 */
trait BuildsPublishGuard
{
    /**
     * @param list<class-string> $acceptedTransportClasses
     */
    private function middleware(
        DbalConnection $connection,
        bool $routedToAsync,
        object $async,
        array $acceptedTransportClasses = [],
    ): RequireTransactionalPublishMiddleware {
        $locator = $this->createStub(SendersLocatorInterface::class);
        $locator->method('getSenders')->willReturn(
            $routedToAsync ? ['async' => $this->createStub(SenderInterface::class)] : [],
        );

        return new RequireTransactionalPublishMiddleware(
            $this->entityManager($connection),
            $locator,
            $this->validator($connection, $async, $acceptedTransportClasses),
        );
    }

    /**
     * @param list<class-string> $acceptedTransportClasses
     */
    private function validator(
        DbalConnection $connection,
        object $async,
        array $acceptedTransportClasses,
    ): AsyncTransportValidator {
        return new AsyncTransportValidator(
            new ServiceLocator(['async' => static fn (): object => $async]),
            $this->entityManager($connection),
            $acceptedTransportClasses,
        );
    }

    private function entityManager(DbalConnection $connection): EntityManagerInterface
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);

        return $entityManager;
    }

    private function connection(bool $inTransaction): DbalConnection
    {
        $connection = $this->createStub(DbalConnection::class);
        $connection->method('isTransactionActive')->willReturn($inTransaction);

        return $connection;
    }

    private function doctrineTransportOn(DbalConnection $connection): DoctrineTransport
    {
        return new DoctrineTransport(
            new Connection(['table_name' => 'messenger_messages'], $connection),
            new PhpSerializer(),
        );
    }

    private function passThroughStack(): StackInterface
    {
        $next = $this->createStub(MiddlewareInterface::class);
        $next->method('handle')->willReturnArgument(0);

        $stack = $this->createStub(StackInterface::class);
        $stack->method('next')->willReturn($next);

        return $stack;
    }

    /**
     * A stack that fails the test if the guard lets the envelope reach the next middleware.
     */
    private function refusingStack(): StackInterface
    {
        $stack = $this->createMock(StackInterface::class);
        $stack->expects($this->never())->method('next');

        return $stack;
    }
}
