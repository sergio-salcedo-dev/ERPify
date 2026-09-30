<?php

declare(strict_types=1);

namespace Erpify\Shared\Persistence\Infrastructure;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;

/**
 * Ends a worker message's claim on the database: whatever transaction handling it opened and did not close is
 * rolled back, see {@see LeakedTransactionContainment}. It never throws — a throw here escapes the worker loop
 * and stops the consumer — so a log line naming the message class is the whole of its report.
 *
 * **A failed message is contained BEFORE its retry.** `WorkerMessageFailedEvent` runs before the ack, and the
 * retry listener's re-queue `INSERT` and the `reject()` `DELETE` run on the same connection; left inside the
 * leaked transaction they would roll back with it, the retry count would never move and the message would never
 * reach `failed`. Rolled back first, the retry strategy applies as configured.
 *
 * **A handled message is contained AFTER its ack, deliberately.** The ack's `DELETE` then runs inside the leaked
 * transaction and rolls back with it, so a handler whose work never committed does not have its message consumed.
 * The message comes back once the transport's redelivery timeout has passed, not on the next poll — `get()`
 * committed `delivered_at` before the handler ran — and a handler that leaks every time comes back every time,
 * with a `critical` each time. Rolling back before the ack would instead lose the message for good.
 *
 * **Outside `test`, every tick checks from zero.** A message that arrives with a transaction already open met a
 * leak that escaped an earlier boundary; an idle tick is where a batch handler's flush would leak. Both are
 * contained from zero, since nothing legitimately spans a message there. Under `test` the baseline is the level
 * observed on receipt, and an idle tick does nothing.
 *
 * If a leaked transaction is ABORTED, the ack's `DELETE` fails before the running tick: the consumer stops, the
 * process manager restarts it, and the dropped connection is what rolls back — the message is redelivered.
 */
final class MessageTransactionBoundaryListener
{
    private ?int $baseline = null;

    private ?string $messageClass = null;

    private readonly bool $baselineIsObserved;

    public function __construct(
        private readonly LeakedTransactionContainment $containment,
        #[Autowire(param: 'kernel.environment')]
        string $environment,
    ) {
        $this->baselineIsObserved = 'test' === $environment;
    }

    #[AsEventListener(event: WorkerMessageReceivedEvent::class)]
    public function onMessageReceived(WorkerMessageReceivedEvent $event): void
    {
        $this->messageClass = $event->getEnvelope()->getMessage()::class;

        if (!$this->baselineIsObserved) {
            $this->containment->containAbove(0, [
                'boundary' => 'worker_message_start',
                'message_class' => $this->messageClass,
            ]);
        }

        $this->baseline = $this->baselineIsObserved ? $this->containment->nestingLevel() : 0;
    }

    /** Above `AddErrorDetailsStampListener` (200) and the retry listener (100), both of which write. */
    #[AsEventListener(event: WorkerMessageFailedEvent::class, priority: 1024)]
    public function onMessageFailed(): void
    {
        if (null !== $this->baseline) {
            $this->containment->containAbove($this->baseline, [
                'boundary' => 'worker_message_failed',
                'message_class' => $this->messageClass,
            ]);
        }
    }

    /** Ahead of Messenger's `ResetServicesListener` (-1024), so the services reset over a clean connection. */
    #[AsEventListener(event: WorkerRunningEvent::class)]
    public function onWorkerRunning(): void
    {
        if (null === $this->baseline) {
            if (!$this->baselineIsObserved) {
                $this->containment->containAbove(0, ['boundary' => 'worker_idle']);
            }

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
