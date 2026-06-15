<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Context;

use Behat\Step\When;
use Erpify\Tests\Behat\Context\Abstraction\AbstractContext;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Messenger\EventListener\StopWorkerOnFailureLimitListener;
use Symfony\Component\Messenger\EventListener\StopWorkerOnMemoryLimitListener;
use Symfony\Component\Messenger\EventListener\StopWorkerOnMessageLimitListener;
use Symfony\Component\Messenger\EventListener\StopWorkerOnTimeLimitListener;

/**
 * Drives the real `messenger:consume` worker so a scenario can assert the effects that only the
 * full consuming pipeline produces — the bus middleware on the receiving side (idempotency claim
 * in `handled_domain_event`, retries to the `failed` transport), not just a handler callable.
 * For a lightweight handler-only invocation use {@see MessengerContext} "message N ... is processed".
 *
 * Two safeguards make the worker safe inside a long-lived Behat process:
 *   - a `--time-limit` backstop so a `--limit` larger than the queued count cannot hang the run;
 *   - removal of stop-worker listeners left over from an earlier consume in the same process —
 *     they accumulate on the shared dispatcher and the smallest limit would otherwise stop the
 *     worker prematurely. `messenger:consume` re-registers fresh ones from this run's options.
 */
final class MessengerConsumeContext extends AbstractContext
{
    /**
     * Backstop wall-clock budget (seconds) for a single consume, so an over-stated message limit
     * degrades to "stop after a few seconds" instead of blocking the suite forever.
     */
    private const int TIME_LIMIT_SECONDS = 5;

    public function __construct(
        private readonly KernelInterface $kernel,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    #[When('I consume :count message from the :transportName transport')]
    #[When('I consume :count messages from the :transportName transport')]
    public function iConsumeMessagesFromTransport(int $count, string $transportName): void
    {
        $this->removeStaleStopWorkerListeners();

        $application = new Application($this->kernel);
        $application->setAutoExit(false);

        $input = new ArrayInput([
            'command' => 'messenger:consume',
            'receivers' => [$transportName],
            '--limit' => $count,
            '--time-limit' => self::TIME_LIMIT_SECONDS,
            '--no-interaction' => true,
        ]);
        $input->setInteractive(false);

        $exitCode = $application->run($input, new BufferedOutput());

        self::assertSame(
            0,
            $exitCode,
            \sprintf('messenger:consume on transport "%s" exited with code %d', $transportName, $exitCode),
        );
    }

    private function removeStaleStopWorkerListeners(): void
    {
        // `messenger:consume` registers a fresh stop listener per run; across scenarios in one
        // process the old ones accumulate on the shared dispatcher, and a stale message-limit
        // smaller than the current `--limit` would stop the worker early. Purge all four so only
        // this run's freshly-registered conditions apply.
        /** @var list<class-string<EventSubscriberInterface>> $stopListenerClasses */
        $stopListenerClasses = [
            StopWorkerOnMessageLimitListener::class,
            StopWorkerOnTimeLimitListener::class,
            StopWorkerOnMemoryLimitListener::class,
            StopWorkerOnFailureLimitListener::class,
        ];

        foreach ($stopListenerClasses as $stopListenerClass) {
            foreach (\array_keys($stopListenerClass::getSubscribedEvents()) as $eventName) {
                foreach ($this->eventDispatcher->getListeners($eventName) as $listener) {
                    if ($this->isListenerOf($listener, $stopListenerClass)) {
                        $this->eventDispatcher->removeListener($eventName, $listener);
                    }
                }
            }
        }
    }

    /**
     * @param class-string $class
     *
     * @phpstan-assert-if-true callable $listener
     */
    private function isListenerOf(mixed $listener, string $class): bool
    {
        // A subscriber listener is registered as a `[$instance, $method]` callable array; matching
        // the instance type both selects the stale listener and proves it is a callable to remove.
        return \is_array($listener) && ($listener[0] ?? null) instanceof $class;
    }
}
