<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Event\Infrastructure\Messenger;

use Erpify\Shared\Event\Domain\DomainEvent;
use Erpify\Shared\Event\Infrastructure\Messenger\AsyncTransportNotTransactional;
use Erpify\Shared\Event\Infrastructure\Messenger\PublishedOutsideTransaction;
use Erpify\Shared\Event\Infrastructure\Messenger\RequireTransactionalPublishMiddleware;
use Generator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Sender\SendersLocatorInterface;

/**
 * What the guard demands of `async` once a message is routed to it. The transaction half is
 * {@see RequireTransactionalPublishMiddlewareTest}; which senders a guarded message may have is
 * {@see RequireTransactionalPublishSendersTest}.
 *
 * @internal
 */
#[CoversClass(RequireTransactionalPublishMiddleware::class)]
#[CoversClass(AsyncTransportNotTransactional::class)]
final class RequireTransactionalPublishTransportTest extends TestCase
{
    use BuildsPublishGuard;

    #[Test]
    public function itRefusesARoutedMessageWhenAsyncIsNotTheDoctrineTransport(): void
    {
        $this->expectException(AsyncTransportNotTransactional::class);
        $this->expectExceptionMessageIsOrContains(InMemoryTransport::class);

        $this->middleware($this->connection(inTransaction: true), true, new InMemoryTransport())
            ->handle(new Envelope($this->createStub(DomainEvent::class)), $this->refusingStack())
        ;
    }

    #[Test]
    public function itRefusesARoutedMessageWhenAsyncHoldsAnotherConnectionToTheSameDatabase(): void
    {
        $this->expectException(AsyncTransportNotTransactional::class);
        $this->expectExceptionMessageIsOrContains('different connection');

        $this->middleware(
            $this->connection(inTransaction: true),
            true,
            $this->doctrineTransportOn($this->connection(inTransaction: true)),
        )->handle(new Envelope($this->createStub(DomainEvent::class)), $this->refusingStack());
    }

    #[Test]
    public function itAdmitsTheInMemoryDoubleOnlyWhenConfigured(): void
    {
        $envelope = new Envelope($this->createStub(DomainEvent::class));

        $result = $this->middleware(
            $this->connection(inTransaction: true),
            true,
            new InMemoryTransport(),
            [InMemoryTransport::class],
        )->handle($envelope, $this->passThroughStack());

        $this->assertSame($envelope, $result);
    }

    #[Test]
    public function itStillRequiresATransactionForTheInMemoryDouble(): void
    {
        $this->expectException(PublishedOutsideTransaction::class);

        $this->middleware(
            $this->connection(inTransaction: false),
            true,
            new InMemoryTransport(),
            [InMemoryTransport::class],
        )->handle(new Envelope($this->createStub(DomainEvent::class)), $this->refusingStack());
    }

    #[Test]
    public function itDoesNotLeakTheDsnOfARoutedTransportThatCannotBeBuilt(): void
    {
        $credential = \bin2hex(\random_bytes(8));
        $locator = $this->createStub(SendersLocatorInterface::class);
        $locator->method('getSenders')->willReturnCallback(static function () use ($credential): Generator {
            yield 'async' => throw new RuntimeException(
                \sprintf('No transport supports amqp://user:%s@broker:5672/%%2f/messages.', $credential),
            );
        });

        $connection = $this->connection(inTransaction: true);
        $middleware = new RequireTransactionalPublishMiddleware(
            $this->entityManager($connection),
            $locator,
            $this->validator($connection, new InMemoryTransport(), []),
        );

        try {
            $middleware->handle(new Envelope($this->createStub(DomainEvent::class)), $this->refusingStack());
            $this->fail('A transport that cannot be built must refuse the publication.');
        } catch (AsyncTransportNotTransactional $asyncTransportNotTransactional) {
            $this->assertStringContainsString('cannot be built', $asyncTransportNotTransactional->getMessage());
            $this->assertStringNotContainsString($credential, $asyncTransportNotTransactional->getMessage());
            $this->assertStringNotContainsString('broker', $asyncTransportNotTransactional->getMessage());
        }
    }
}
