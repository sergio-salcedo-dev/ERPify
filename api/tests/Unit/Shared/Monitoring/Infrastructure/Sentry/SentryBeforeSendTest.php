<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Monitoring\Infrastructure\Sentry;

use Erpify\Shared\ErrorContract\Domain\Exception\Conflict;
use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;
use Erpify\Shared\Monitoring\Infrastructure\Sentry\SentryBeforeSend;
use Erpify\Shared\Monitoring\Infrastructure\Sentry\SentryEventFilter;
use Erpify\Shared\Monitoring\Infrastructure\Sentry\SentryEventScrubber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sentry\Event;
use Sentry\EventHint;

/**
 * @internal
 */
#[CoversClass(SentryBeforeSend::class)]
final class SentryBeforeSendTest extends TestCase
{
    public function testDropsClientErrorWithoutScrubbing(): void
    {
        $hint = EventHint::fromArray([
            'exception' => new class ('conflict', 'in use') extends DomainException implements Conflict {
            },
        ]);

        $this->assertNotInstanceOf(Event::class, $this->beforeSend()(Event::createEvent(), $hint));
    }

    public function testKeepsAndScrubsNonClientErrorEvent(): void
    {
        $event = Event::createEvent();
        $event->setExtra(['token' => 'top-secret', 'safe' => 'keep']);

        $hint = EventHint::fromArray(['exception' => new RuntimeException('boom')]);

        $result = $this->beforeSend()($event, $hint);

        $this->assertSame($event, $result);
        $this->assertSame(['safe' => 'keep'], $event->getExtra(), 'Kept events are still PII-scrubbed.');
    }

    private function beforeSend(): SentryBeforeSend
    {
        return new SentryBeforeSend(new SentryEventFilter(), new SentryEventScrubber());
    }
}
