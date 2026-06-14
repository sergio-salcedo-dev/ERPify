<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Monitoring\Infrastructure\Sentry;

use Doctrine\DBAL\Exception\ConnectionException;
use Doctrine\DBAL\Exception\ConnectionLost;
use Erpify\Shared\Domain\Exception\Conflict;
use Erpify\Shared\Domain\Exception\DomainException;
use Erpify\Shared\Monitoring\Infrastructure\Sentry\SentryEventFilter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Sentry\Event;
use Sentry\EventHint;
use Symfony\Component\Messenger\Exception\TransportException;

/**
 * @internal
 */
#[CoversClass(SentryEventFilter::class)]
final class SentryEventFilterTest extends TestCase
{
    public function testDropsEventWhoseHintExceptionIsAClientError(): void
    {
        $hint = EventHint::fromArray([
            'exception' => new class ('conflict', 'in use') extends DomainException implements Conflict {
            },
        ]);

        $result = (new SentryEventFilter())(Event::createEvent(), $hint);

        $this->assertNotInstanceOf(
            Event::class,
            $result,
            'A ClientError (expected 4xx) must be dropped before Sentry.',
        );
    }

    public function testKeepsEventWhoseHintExceptionIsNotAClientError(): void
    {
        $event = Event::createEvent();
        $hint = EventHint::fromArray(['exception' => new RuntimeException('boom')]);

        $this->assertSame($event, (new SentryEventFilter())($event, $hint));
    }

    public function testKeepsEventWhoseDomainExceptionHasNoClientErrorMarker(): void
    {
        // A marker-less DomainException maps to unhandled-exception (500) — it MUST reach Sentry.
        $event = Event::createEvent();
        $hint = EventHint::fromArray([
            'exception' => new class ('unexpected-state', 'Project is corrupted') extends DomainException {
            },
        ]);

        $this->assertSame($event, (new SentryEventFilter())($event, $hint));
    }

    public function testKeepsEventWhenHintIsNull(): void
    {
        $event = Event::createEvent();

        $this->assertSame($event, (new SentryEventFilter())($event, null));
    }

    public function testKeepsEventWhenHintCarriesNoException(): void
    {
        $event = Event::createEvent();

        $this->assertSame($event, (new SentryEventFilter())($event, EventHint::fromArray([])));
    }

    public function testDropsWorkerConnectionLossFromConsumeCommand(): void
    {
        // messenger:consume hit a connection loss while polling (LISTEN messenger_messages) during a
        // stack teardown — a self-healing lifecycle artifact, not an actionable fault.
        $event = Event::createEvent();
        $event->setTag('console.command', 'messenger:consume');

        $hint = EventHint::fromArray([
            'exception' => new TransportException('terminating connection', 0, $this->connectionLost()),
        ]);

        $this->assertNotInstanceOf(Event::class, (new SentryEventFilter())($event, $hint));
    }

    public function testKeepsConnectionLossNotOriginatingFromTheWorker(): void
    {
        // Same DBAL connection-loss class, but no messenger:consume tag and no worker-transport
        // frame: a DB outage during HTTP request handling. It MUST still reach Sentry.
        $event = Event::createEvent();

        $hint = EventHint::fromArray(['exception' => $this->connectionException()]);

        $this->assertSame($event, (new SentryEventFilter())($event, $hint));
    }

    private function connectionLost(): ConnectionLost
    {
        // DBAL connection exceptions take a driver exception in their constructor; instantiating
        // without it keeps the test free of vendor-internal plumbing — only the type matters here.
        return (new ReflectionClass(ConnectionLost::class))->newInstanceWithoutConstructor();
    }

    private function connectionException(): ConnectionException
    {
        return (new ReflectionClass(ConnectionException::class))->newInstanceWithoutConstructor();
    }
}
