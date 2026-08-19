<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Monitoring\Infrastructure\Sentry;

use Erpify\Shared\Monitoring\Infrastructure\Sentry\SentryEventScrubber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sentry\Event;
use Sentry\ExceptionDataBag;

/**
 * The half of the scrub a key denylist cannot perform, kept apart from {@see SentryEventScrubberTest} because
 * the two answer different questions about the same object. That one asks which FIELDS leave the process; this
 * one asks what survives inside a field that is kept — free text, where an address rides in the VALUE and the
 * name of the field carrying it is no help at all.
 *
 * The sink is what makes the distinction worth a class: Sentry retention belongs to a third party, and no
 * erasure path this repository can write reaches it.
 *
 * @internal
 */
#[CoversClass(SentryEventScrubber::class)]
final class SentryEventAddressRedactionTest extends TestCase
{
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

    /**
     * The exception message is what the tracker titles an issue with, and the producer is measured rather than
     * imagined: the console error listener is registered at priority -64, `SentryEventFilter` discards only
     * `ClientError` and worker transport noise (an `RfcComplianceException` is neither), and
     * `bin/console mailer:test <address>` raises one quoting the argument verbatim.
     */
    public function testRedactsAnAddressCarriedInAnExceptionValue(): void
    {
        $event = Event::createEvent();
        $event->setExceptions([new ExceptionDataBag(
            new RuntimeException('Email "alice@example.test" does not comply with addr-spec of RFC 2822.'),
        )]);

        $scrubbed = (new SentryEventScrubber())($event)->getExceptions();

        $this->assertSame(
            ['Email "REDACTED" does not comply with addr-spec of RFC 2822.'],
            \array_map(static fn (ExceptionDataBag $bag): string => $bag->getValue(), $scrubbed),
        );
    }

    /**
     * The one exception type holding an `@` is an anonymous class, whose name is a file path rather than a
     * person — so redacting it would swallow the only identification the frame carries and protect nobody.
     */
    public function testLeavesAnExceptionTypeAloneEvenWhenItHoldsAnAt(): void
    {
        $anonymous = 'class@anonymous/app/api/src/Shared/Mailer/Infrastructure/RedactingMailer.php:12$0';
        $event = Event::createEvent();
        $bag = new ExceptionDataBag(new RuntimeException('no address here'));
        $bag->setType($anonymous);

        $event->setExceptions([$bag]);

        $scrubbed = (new SentryEventScrubber())($event)->getExceptions();

        $this->assertSame(
            [$anonymous],
            \array_map(static fn (ExceptionDataBag $inspected): string => $inspected->getType(), $scrubbed),
        );
    }

    /**
     * Three fields of one message, and the SDK sets them together: redacting the template alone would leave
     * the address in the rendering a reader actually sees.
     */
    public function testRedactsAnAddressInTheEventsOwnMessageAndItsParameters(): void
    {
        $event = Event::createEvent();
        $event->setMessage(
            'delivery to %s failed',
            ['alice@example.test'],
            'delivery to alice@example.test failed',
        );

        (new SentryEventScrubber())($event);

        $this->assertSame('delivery to %s failed', $event->getMessage());
        $this->assertSame(['REDACTED'], $event->getMessageParams());
        $this->assertSame('delivery to REDACTED failed', $event->getMessageFormatted());
    }
}
