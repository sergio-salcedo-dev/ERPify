<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Event\Infrastructure\Messenger;

use DateTimeImmutable;
use Erpify\Shared\Event\Application\DeadLetterEntry;
use Erpify\Shared\Event\Infrastructure\Messenger\MessengerDeadLetterReader;
use Erpify\Tests\Unit\Shared\Domain\Event\SerializableTestDomainEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;

/**
 * @internal
 */
#[CoversClass(MessengerDeadLetterReader::class)]
final class MessengerDeadLetterReaderTest extends TestCase
{
    #[Test]
    public function itCountsThroughTheTransport(): void
    {
        $this->assertSame(7, (new MessengerDeadLetterReader($this->transport([], 7)))->count());
    }

    #[Test]
    public function itMapsListedEnvelopesToTriageEntries(): void
    {
        $event = new SerializableTestDomainEvent(
            'aggregate-1',
            'event-id-1',
            new DateTimeImmutable('2026-06-20 09:00'),
        );
        $failedAt = new DateTimeImmutable('2026-06-20 10:30');
        $envelope = new Envelope($event, [
            new RedeliveryStamp(3, $failedAt),
            ErrorDetailsStamp::create(new RuntimeException('mailer down')),
        ]);

        $entries = (new MessengerDeadLetterReader($this->transport([$envelope], 1)))->entries();

        $entry = \reset($entries);
        $this->assertInstanceOf(DeadLetterEntry::class, $entry);
        $this->assertSame(SerializableTestDomainEvent::class, $entry->messageType);
        $this->assertSame('event-id-1', $entry->eventId);
        $this->assertSame(
            $failedAt->format(DateTimeImmutable::ATOM),
            $entry->failedAt?->format(DateTimeImmutable::ATOM),
        );
        $this->assertSame(RuntimeException::class, $entry->errorClass);
        $this->assertSame('mailer down', $entry->errorMessage);
    }

    #[Test]
    public function itLeavesEventIdNullForNonDomainEventMessages(): void
    {
        $transport = $this->transport([new Envelope(new RuntimeException('not an event'))], 1);

        $entries = (new MessengerDeadLetterReader($transport))->entries();

        $entry = \reset($entries);
        $this->assertInstanceOf(DeadLetterEntry::class, $entry);
        $this->assertNull($entry->eventId);
        $this->assertNotInstanceOf(DateTimeImmutable::class, $entry->failedAt);
        $this->assertNull($entry->errorClass);
    }

    #[Test]
    public function itForwardsTheLimitToTheTransport(): void
    {
        /** @var ListableReceiverInterface&MessageCountAwareInterface&MockObject $transport */
        $transport = $this->createMockForIntersectionOfInterfaces(
            [ListableReceiverInterface::class, MessageCountAwareInterface::class],
        );
        $transport->expects($this->once())->method('all')->with(5)->willReturn([]);

        (new MessengerDeadLetterReader($transport))->entries(5);
    }

    #[Test]
    public function itReadsEmptyWhenTheTransportCannotBeListedOrCounted(): void
    {
        $reader = new MessengerDeadLetterReader(new InMemoryTransport());

        $this->assertSame(0, $reader->count());
        $this->assertSame([], $reader->entries());
    }

    /**
     * @param list<Envelope> $envelopes
     */
    private function transport(array $envelopes, int $count): ListableReceiverInterface&MessageCountAwareInterface
    {
        /** @var ListableReceiverInterface&MessageCountAwareInterface&Stub $transport */
        $transport = $this->createStubForIntersectionOfInterfaces(
            [ListableReceiverInterface::class, MessageCountAwareInterface::class],
        );
        $transport->method('all')->willReturn($envelopes);
        $transport->method('getMessageCount')->willReturn($count);

        return $transport;
    }
}
