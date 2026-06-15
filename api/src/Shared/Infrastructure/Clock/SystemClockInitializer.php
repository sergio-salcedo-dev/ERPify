<?php

declare(strict_types=1);

namespace Erpify\Shared\Infrastructure\Clock;

use Erpify\Shared\Domain\Clock\Clock;
use Erpify\Shared\Domain\Clock\SystemClock;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;

/**
 * Pins the ambient {@see SystemClock} to the container-wired {@see Clock} (the {@see SymfonyClock}
 * adapter) at the very start of every entry point — HTTP request, console command, and Messenger
 * worker message. Without this, code that reads time through `SystemClock` (aggregates and domain
 * events, which no container can inject into) would fall back to `SystemClock`'s standalone
 * `NativeClock`, leaving two independent time sources: the injected `Clock` for DI-reachable
 * consumers and `NativeClock` for the domain. Pinning collapses them into one.
 *
 * Runs in every environment by design: in prod it routes the domain/event timestamps through the
 * same Symfony Clock the rest of the app shares; under test a `MockClock` wired as the `Clock`
 * propagates into the domain too. The body is a cheap, idempotent static write, safe to repeat on
 * each request in the FrankenPHP worker loop. A high priority pins the clock before any other
 * listener on the same event can read it.
 *
 * The listened events carry no data this needs, so `__invoke()` takes no argument and the dispatcher
 * call's event payload is simply ignored.
 */
final readonly class SystemClockInitializer
{
    private const int PRIORITY = 4096;

    public function __construct(private Clock $clock)
    {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: self::PRIORITY)]
    #[AsEventListener(event: ConsoleEvents::COMMAND, priority: self::PRIORITY)]
    #[AsEventListener(event: WorkerMessageReceivedEvent::class, priority: self::PRIORITY)]
    public function __invoke(): void
    {
        SystemClock::set($this->clock);
    }
}
