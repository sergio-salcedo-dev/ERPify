<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Infrastructure\Monitoring;

use Erpify\Shared\Infrastructure\Monitoring\SentryEventScrubber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sentry\Event;

/**
 * @internal
 */
#[CoversClass(SentryEventScrubber::class)]
final class SentryEventScrubberTest extends TestCase
{
    public function testStripsDenylistedKeysFromExtraAtEveryDepth(): void
    {
        $event = Event::createEvent();
        $event->setExtra([
            'token' => 'top-secret',
            'safe' => 'keep',
            'nested' => ['password' => 'p', 'ok' => 1],
        ]);

        (new SentryEventScrubber())($event);

        $this->assertSame(['safe' => 'keep', 'nested' => ['ok' => 1]], $event->getExtra());
    }

    public function testStripsDenylistedKeysFromNestedRequestSubArrays(): void
    {
        $event = Event::createEvent();
        $event->setRequest([
            'url' => 'https://erpify.test/api',
            'data' => ['user' => ['password' => 'p', 'name' => 'amelia']],
            'cookies' => ['secret' => 's', 'sid' => 'x'],
        ]);

        (new SentryEventScrubber())($event);

        $this->assertSame([
            'url' => 'https://erpify.test/api',
            'data' => ['user' => ['name' => 'amelia']],
            'cookies' => ['sid' => 'x'],
        ], $event->getRequest());
    }

    public function testStripsDenylistedParamsFromRawQueryStringValue(): void
    {
        $event = Event::createEvent();
        $event->setRequest(['query_string' => 'token=abc&q=banks&iban=DE89']);

        (new SentryEventScrubber())($event);

        // token + iban (denylisted) stripped; q kept and re-encoded.
        $this->assertSame(['query_string' => 'q=banks'], $event->getRequest());
    }

    public function testLeavesAnEventWithoutExtraOrRequestUntouched(): void
    {
        $event = Event::createEvent();

        $result = (new SentryEventScrubber())($event);

        $this->assertSame($event, $result);
        $this->assertSame([], $event->getExtra());
        $this->assertSame([], $event->getRequest());
    }
}
