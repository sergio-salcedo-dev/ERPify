<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Infrastructure\Clock;

use DateTimeInterface;
use Erpify\Shared\Domain\Clock\SystemClock;
use Erpify\Shared\Infrastructure\Clock\SymfonyClock;
use Erpify\Shared\Infrastructure\Clock\SystemClockInitializer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;

/**
 * @internal
 */
#[CoversClass(SystemClockInitializer::class)]
final class SystemClockInitializerTest extends TestCase
{
    protected function tearDown(): void
    {
        SystemClock::reset();

        parent::tearDown();
    }

    #[Test]
    public function itPinsTheAmbientSystemClockToTheInjectedClock(): void
    {
        $instant = '2026-01-02T03:04:05+00:00';
        $initializer = new SystemClockInitializer(new SymfonyClock(new MockClock($instant)));

        ($initializer)();

        $this->assertSame($instant, SystemClock::now()->format(DateTimeInterface::ATOM));
    }

    #[Test]
    public function itListensOnEveryApplicationEntryPoint(): void
    {
        $attributes = (new ReflectionMethod(SystemClockInitializer::class, '__invoke'))
            ->getAttributes(AsEventListener::class)
        ;

        $events = \array_map(static fn (object $a): ?string => $a->newInstance()->event, $attributes);

        $this->assertContains(KernelEvents::REQUEST, $events);
        $this->assertContains(ConsoleEvents::COMMAND, $events);
        $this->assertContains(WorkerMessageReceivedEvent::class, $events);
    }
}
