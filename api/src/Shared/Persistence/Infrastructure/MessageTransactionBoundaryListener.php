<?php

declare(strict_types=1);

namespace Erpify\Shared\Persistence\Infrastructure;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;

/**
 * Ends a worker message's claim on the database: whatever transaction handling it opened and did not close is
 * rolled back once the worker is done with the message, see {@see LeakedTransactionContainment}.
 *
 * **After the acknowledgement, deliberately.** The worker acks before it dispatches `WorkerRunningEvent`, and
 * on the Doctrine transport that ack is a `DELETE` on the same connection — so it ran INSIDE the leaked
 * transaction and is rolled back with it, together with the at-most-once claim the handler took. The message
 * is therefore delivered again, which is the correct outcome: nothing its handler did was ever committed.
 *
 * **Never throws.** A throw here escapes the worker loop and stops the consumer; a log line naming the message
 * class is what an operator can act on.
 *
 * Ahead of Messenger's own `ResetServicesListener` (priority -1024), so the connection is already clean when
 * the services are reset.
 */
final class MessageTransactionBoundaryListener
{
    private ?int $baseline = null;

    private ?string $messageClass = null;

    public function __construct(private readonly LeakedTransactionContainment $containment)
    {
    }

    #[AsEventListener(event: WorkerMessageReceivedEvent::class)]
    public function onMessageReceived(WorkerMessageReceivedEvent $event): void
    {
        $this->baseline = $this->containment->nestingLevel();
        $this->messageClass = $event->getEnvelope()->getMessage()::class;
    }

    #[AsEventListener(event: WorkerRunningEvent::class)]
    public function onWorkerRunning(): void
    {
        if (null === $this->baseline) {
            return;
        }

        $baseline = $this->baseline;
        $messageClass = $this->messageClass;
        $this->baseline = null;
        $this->messageClass = null;

        $this->containment->containAbove($baseline, [
            'boundary' => 'worker_message',
            'message_class' => $messageClass,
        ]);
    }
}
