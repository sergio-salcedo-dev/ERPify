<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Event\Infrastructure\Messenger;

use ArrayObject;
use Countable;
use Erpify\Shared\Event\Infrastructure\Messenger\PublishedOutsideTransaction;
use Erpify\Shared\Event\Infrastructure\Messenger\RequireTransactionalPublishMiddleware;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;

/**
 * Drives the guard through Messenger's real {@see SendersLocator}, so "routed to `async`" is whatever the
 * framework's routing says: by interface, by wildcard and by `TransportNamesStamp`, and not by a sender
 * alias that merely resembles it. Outside a transaction a message that is not a `DomainEvent` is refused
 * only when it is routed to `async`, which makes the refusal the observable.
 *
 * @internal
 */
#[CoversClass(RequireTransactionalPublishMiddleware::class)]
final class RequireTransactionalPublishRoutingTest extends TestCase
{
    use BuildsPublishGuard;

    #[Test]
    public function aMessageRoutedToAsyncByInterfaceIsGuarded(): void
    {
        $this->expectException(PublishedOutsideTransaction::class);

        $this->middlewareRoutedBy([Countable::class => ['async']])
            ->handle(new Envelope(new ArrayObject()), $this->refusingStack())
        ;
    }

    #[Test]
    public function aMessageRoutedToAsyncByWildcardIsGuarded(): void
    {
        $this->expectException(PublishedOutsideTransaction::class);

        $this->middlewareRoutedBy(['*' => ['async']])
            ->handle(new Envelope(new stdClass()), $this->refusingStack())
        ;
    }

    #[Test]
    public function anEnvelopeAddressedToAsyncByStampIsGuarded(): void
    {
        $this->expectException(PublishedOutsideTransaction::class);

        $this->middlewareRoutedBy([])
            ->handle(new Envelope(new stdClass(), [new TransportNamesStamp(['async'])]), $this->refusingStack())
        ;
    }

    #[Test]
    public function aMessageWhoseOnlySenderIsAnotherAliasIsNotTreatedAsRouted(): void
    {
        $envelope = new Envelope(new stdClass());

        $result = $this->middlewareRoutedBy([stdClass::class => ['failed']])
            ->handle($envelope, $this->passThroughStack())
        ;

        $this->assertSame($envelope, $result);
    }

    /**
     * @param array<string, list<string>> $sendersMap
     */
    private function middlewareRoutedBy(array $sendersMap): RequireTransactionalPublishMiddleware
    {
        $connection = $this->connection(inTransaction: false);
        $sender = $this->createStub(SenderInterface::class);

        return new RequireTransactionalPublishMiddleware(
            $this->entityManager($connection),
            new SendersLocator($sendersMap, new ServiceLocator([
                'async' => static fn (): SenderInterface => $sender,
                'failed' => static fn (): SenderInterface => $sender,
            ])),
            $this->validator($connection, new InMemoryTransport(), []),
        );
    }
}
