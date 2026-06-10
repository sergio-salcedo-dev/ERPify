<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Monitoring\Infrastructure\Sentry;

use Erpify\Shared\Domain\Exception\Conflict;
use Erpify\Shared\Domain\Exception\DomainException;
use Erpify\Shared\Monitoring\Infrastructure\Sentry\SentryEventFilter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sentry\Event;
use Sentry\EventHint;

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

        $this->assertNotInstanceOf(Event::class, $result, 'A ClientError (expected 4xx) must be dropped before Sentry.');
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
}
