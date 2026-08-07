<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Monitoring\Infrastructure\Sentry;

use Erpify\Shared\Monitoring\Infrastructure\Sentry\SentryEventScrubber;
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

    /**
     * A query string is a URI, so it answers to the URI vocabulary rather than to the map filter's strip
     * semantics: the key stays and the value becomes the sentinel. That is what makes an event comparable
     * with the access log and the per-error log line, which write the same token over the same axes — and
     * it keeps the operator able to tell a request that carried a token from one that did not.
     */
    public function testRedactsDenylistedParamsInTheRawQueryStringValue(): void
    {
        $event = Event::createEvent();
        $event->setRequest(['query_string' => 'token=abc&q=banks&iban=DE89']);

        (new SentryEventScrubber())($event);

        $this->assertSame(
            ['query_string' => 'token=REDACTED&q=banks&iban=REDACTED'],
            $event->getRequest(),
        );
    }

    /**
     * The axes that make this a GDPR surface rather than a secrets one. `parse_str()` would nest
     * `filters[0][value]` into keys no rule matches, which is how the positional grammar used to travel
     * intact; the pairs are read as they were sent instead.
     */
    public function testRedactsTheIdentityAxesInTheRawQueryStringValue(): void
    {
        $event = Event::createEvent();
        $event->setRequest([
            'query_string' => 'actorId=8f14e45f-ceea&filters%5B0%5D%5Bvalue%5D=8f14e45f-ceea&level=security',
        ]);

        (new SentryEventScrubber())($event);

        $this->assertSame(
            ['query_string' => 'actorId=REDACTED&filters%5B0%5D%5Bvalue%5D=REDACTED&level=security'],
            $event->getRequest(),
        );
    }

    /**
     * The SDK builds `url` from the whole URI, so it carries the query a second time. Redacting only
     * `query_string` leaves the same identifiers on the same event.
     */
    public function testRedactsTheQueryCarriedInsideTheRequestUrl(): void
    {
        $event = Event::createEvent();
        $event->setRequest([
            'url' => 'https://erpify.test/api/v1/backoffice/audit?actorId=8f14e45f-ceea&level=security',
        ]);

        (new SentryEventScrubber())($event);

        $this->assertSame(
            ['url' => 'https://erpify.test/api/v1/backoffice/audit?actorId=REDACTED&level=security'],
            $event->getRequest(),
        );
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
