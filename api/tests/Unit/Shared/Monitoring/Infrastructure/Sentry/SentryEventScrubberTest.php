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
     * The axes that make this a GDPR surface rather than a secrets one. The pairs are read as the caller
     * sent them: normalising through `parse_str()` would nest `filters[0][value]` into the keys `filters`,
     * `0`, `value`, which no rule here matches, so the positional grammar would travel intact.
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
     * The one header whose value is a URI. The recursive scrub above matches key NAMES and the SDK's own
     * sensitive-header list covers credentials only, so an unredacted `Referer` carries the referring
     * document's whole URL — the audit screen's, ids included — into a sink with its own retention.
     */
    public function testRedactsTheQueryCarriedByTheRefererHeader(): void
    {
        $event = Event::createEvent();
        $event->setRequest([
            'headers' => [
                'Referer' => 'https://erpify.test/backoffice/audit?actorId=8f14e45f-ceea&level=security',
                'Accept' => 'application/json',
            ],
        ]);

        (new SentryEventScrubber())($event);

        $this->assertSame([
            'headers' => [
                'Referer' => 'https://erpify.test/backoffice/audit?actorId=REDACTED&level=security',
                'Accept' => 'application/json',
            ],
        ], $event->getRequest());
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

    /**
     * `Full command` is the field the console listener sets to the whole argv line, and the identifier is in
     * its VALUE — so the key denylist cannot reach it. Dropping the field would take the command name with it.
     */
    public function testRedactsAnAddressCarriedInAnExtraValueWhileKeepingTheCommand(): void
    {
        $event = Event::createEvent();
        $event->setExtra(['Full command' => "mailer:test 'alice@example.test'"]);

        $scrubbed = (new SentryEventScrubber())($event)->getExtra();

        // The quotes go with it: an apostrophe is an address byte to the pattern, so the shell quoting around
        // the argument is consumed with the value rather than left framing an empty pair.
        $this->assertSame(['Full command' => 'mailer:test REDACTED'], $scrubbed);
    }

    /** Keying on the field name would repeat the mistake: the next field carrying an address is invisible. */
    public function testRedactsAnAddressUnderAnyExtraKeyAndAtAnyDepth(): void
    {
        $event = Event::createEvent();
        $event->setExtra(['whatever' => ['nested' => 'delivery to bob@example.test failed']]);

        $scrubbed = (new SentryEventScrubber())($event)->getExtra();

        $this->assertSame(['whatever' => ['nested' => 'delivery to REDACTED failed']], $scrubbed);
    }
}
