<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Event\Infrastructure\Messenger;

use Erpify\Shared\Event\Application\EventStore;
use Erpify\Shared\Event\Domain\DomainEvent;
use Erpify\Shared\Event\Infrastructure\Messenger\PersistDomainEventMiddleware;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

/**
 * @internal
 */
#[CoversClass(PersistDomainEventMiddleware::class)]
final class PersistDomainEventMiddlewareTest extends TestCase
{
    #[Test]
    public function itAppendsADomainEventBeforePassingItDownTheStack(): void
    {
        $event = $this->createStub(DomainEvent::class);
        $envelope = new Envelope($event);

        $eventStore = $this->createMock(EventStore::class);
        $eventStore->expects($this->once())->method('append')->with($event);

        $result = (new PersistDomainEventMiddleware($eventStore))->handle($envelope, $this->passThroughStack());

        $this->assertSame($envelope, $result);
    }

    #[Test]
    public function itDoesNotAppendAMessageThatIsNotADomainEvent(): void
    {
        $envelope = new Envelope(new stdClass());

        $eventStore = $this->createMock(EventStore::class);
        $eventStore->expects($this->never())->method('append');

        $result = (new PersistDomainEventMiddleware($eventStore))->handle($envelope, $this->passThroughStack());

        $this->assertSame($envelope, $result);
    }

    private function passThroughStack(): StackInterface
    {
        $next = $this->createStub(MiddlewareInterface::class);
        $next->method('handle')->willReturnArgument(0);

        $stack = $this->createStub(StackInterface::class);
        $stack->method('next')->willReturn($next);

        return $stack;
    }
}
