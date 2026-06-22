<?php

declare(strict_types=1);

namespace Erpify\Shared\Event\Infrastructure\Messenger;

use DateTimeImmutable;
use Erpify\Shared\Event\Application\DeadLetterEntry;
use Erpify\Shared\Event\Application\DeadLetterReader;
use Erpify\Shared\Event\Domain\DomainEvent;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;

/**
 * {@link DeadLetterReader} over the configured Messenger failure transport (bound to
 * `@messenger.transport.failed`).
 *
 * Typed as the read-only {@see ReceiverInterface} — inspection never sends. Only the Doctrine
 * transport used in dev/prod is listable and countable, while the in-memory transport used in tests
 * is neither, so capability is probed at call time: the same wiring holds in every environment and an
 * unlistable transport simply reads as empty rather than breaking the container.
 */
#[AsAlias(DeadLetterReader::class)]
final readonly class MessengerDeadLetterReader implements DeadLetterReader
{
    public function __construct(
        private ReceiverInterface $failedTransport,
    ) {
    }

    #[Override]
    public function count(): int
    {
        return $this->failedTransport instanceof MessageCountAwareInterface
            ? $this->failedTransport->getMessageCount()
            : 0;
    }

    #[Override]
    public function entries(?int $limit = null): array
    {
        if (!$this->failedTransport instanceof ListableReceiverInterface) {
            return [];
        }

        $entries = [];

        foreach ($this->failedTransport->all($limit) as $envelope) {
            $entries[] = $this->toEntry($envelope);
        }

        return $entries;
    }

    private function toEntry(Envelope $envelope): DeadLetterEntry
    {
        $message = $envelope->getMessage();
        $redelivery = $envelope->last(RedeliveryStamp::class);
        $errorDetails = $envelope->last(ErrorDetailsStamp::class);

        return new DeadLetterEntry(
            $message::class,
            $message instanceof DomainEvent ? $message->eventId() : null,
            $redelivery instanceof RedeliveryStamp
                ? DateTimeImmutable::createFromInterface($redelivery->getRedeliveredAt())
                : null,
            $errorDetails?->getExceptionClass(),
            $errorDetails?->getExceptionMessage(),
        );
    }
}
