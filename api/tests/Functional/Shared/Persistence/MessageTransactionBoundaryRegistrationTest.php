<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Persistence;

use Erpify\Shared\Persistence\Infrastructure\MessageTransactionBoundaryListener;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\EventListener\AddErrorDetailsStampListener;
use Symfony\Component\Messenger\EventListener\ResetServicesListener;
use Symfony\Component\Messenger\EventListener\SendFailedMessageForRetryListener;

/**
 * Pins where the worker boundary sits on the container's dispatcher relative to the Messenger listeners its
 * ordering claims are about, reading their priorities from the dispatcher or the class rather than restating them.
 *
 * @internal
 */
#[CoversClass(MessageTransactionBoundaryListener::class)]
final class MessageTransactionBoundaryRegistrationTest extends KernelTestCase
{
    #[Test]
    public function eachCheckIsRegisteredWhereItsClaimNeedsIt(): void
    {
        $dispatcher = self::getContainer()->get('event_dispatcher');
        $this->assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        $this->assertNotNull($this->priorityOn($dispatcher, WorkerMessageReceivedEvent::class));
        $writers = $this->prioritiesOf(
            $dispatcher,
            WorkerMessageFailedEvent::class,
            [SendFailedMessageForRetryListener::class, AddErrorDetailsStampListener::class],
        );
        $this->assertCount(2, $writers, 'a failed-message writer is not registered: vacuous ordering');
        $this->assertGreaterThan(\max($writers), $this->priorityOn($dispatcher, WorkerMessageFailedEvent::class));
        // `messenger:consume` subscribes the services resetter at run time, so its priority is read from the class.
        $resetter = ResetServicesListener::getSubscribedEvents()[WorkerRunningEvent::class] ?? null;
        $this->assertIsArray($resetter);
        $this->assertArrayHasKey(1, $resetter);
        $this->assertGreaterThan($resetter[1], $this->priorityOn($dispatcher, WorkerRunningEvent::class));
    }

    /**
     * @param list<class-string> $owners
     *
     * @return list<int>
     */
    private function prioritiesOf(EventDispatcherInterface $dispatcher, string $eventName, array $owners): array
    {
        $priorities = [];

        foreach ($dispatcher->getListeners($eventName) as $listener) {
            $owner = \is_array($listener) ? $listener[0] : $listener;

            if (\is_object($owner) && \in_array($owner::class, $owners, true)) {
                $priorities[] = (int) $dispatcher->getListenerPriority($eventName, $listener);
            }
        }

        return $priorities;
    }

    private function priorityOn(EventDispatcherInterface $dispatcher, string $eventName): ?int
    {
        foreach ($dispatcher->getListeners($eventName) as $listener) {
            if (\is_array($listener) && $listener[0] instanceof MessageTransactionBoundaryListener) {
                return $dispatcher->getListenerPriority($eventName, $listener);
            }
        }

        return null;
    }
}
