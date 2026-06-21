<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Event\Infrastructure\Bus;

use Erpify\Shared\Event\Domain\DomainEvent;
use Erpify\Shared\Event\Infrastructure\Bus\SymfonyMessengerEventBus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(SymfonyMessengerEventBus::class)]
final class SymfonyMessengerEventBusTest extends TestCase
{
    #[Test]
    public function itForwardsEveryEventToTheMessageBusInOrder(): void
    {
        $messageBus = new RecordingMessageBus();
        $eventBus = new SymfonyMessengerEventBus($messageBus);
        $first = $this->createStub(DomainEvent::class);
        $second = $this->createStub(DomainEvent::class);

        $eventBus->publish($first, $second);

        $this->assertSame([$first, $second], $messageBus->dispatchedMessages);
    }

    #[Test]
    public function itIsANoOpWhenPublishingNoEvents(): void
    {
        $messageBus = new RecordingMessageBus();
        $eventBus = new SymfonyMessengerEventBus($messageBus);

        $eventBus->publish();

        $this->assertSame([], $messageBus->dispatchedMessages);
    }
}
