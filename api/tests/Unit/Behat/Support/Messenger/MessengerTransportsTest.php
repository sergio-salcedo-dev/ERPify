<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Behat\Support\Messenger;

use Erpify\Tests\Behat\Support\Messenger\MessengerTransports;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Pins the two properties this collaborator exists for, neither of which a scenario above it can
 * observe: that a queue is read *whole*, and that a transport it cannot inspect is refused out loud.
 *
 * Both failure modes are silent by construction. A truncated read and an unread queue each satisfy
 * `0 outbox events were created` exactly as an empty one does, so no acceptance scenario can tell
 * them from the real thing — the suite would stay green while asserting nothing. Driving the class
 * directly, over a hand-built container, is what makes them observable at all.
 *
 * The whole-queue read is the one with history: `InMemoryTransport::get()` never declares its fetch
 * size in the signature and defaults it to 1, so leaving it implicit silently caps every count at one.
 *
 * {@see CoversNothing} because the subject is test infrastructure — `tests/` sits outside the coverage
 * allowlist, so there is no production line here to credit.
 *
 * @internal
 */
#[CoversNothing]
final class MessengerTransportsTest extends TestCase
{
    private const string ASYNC = 'async';

    private const string ASYNC_SERVICE = 'messenger.transport.async';

    public function testReadsThePendingQueueWholeRatherThanItsFirstMessage(): void
    {
        $transport = new InMemoryTransport();
        $transport->send(new Envelope(new stdClass()));
        $transport->send(new Envelope(new stdClass()));
        $transport->send(new Envelope(new stdClass()));

        // Three, not one: the upstream fetch size defaults to 1 and stops there.
        $this->assertCount(3, $this->transportsWith($transport)->pending(self::ASYNC));
    }

    public function testRefusesATransportThatIsNotRegistered(): void
    {
        $transports = new MessengerTransports(new Container());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('service messenger.transport.async is not registered');

        $transports->pending(self::ASYNC);
    }

    public function testRefusesATransportItCannotInspect(): void
    {
        // The real class travels in the message: diagnosing a transport that stopped being in-memory
        // should not require opening the container.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('service messenger.transport.async is stdClass');

        $this->transportsWith(new stdClass())->pending(self::ASYNC);
    }

    public function testSentSurvivesTheDrainThatEmptiesPending(): void
    {
        $transport = new InMemoryTransport();
        $envelope = $transport->send(new Envelope(new stdClass()));

        $transports = $this->transportsWith($transport);
        $transports->reject(self::ASYNC, $envelope);

        $this->assertSame([], $transports->pending(self::ASYNC));
        $this->assertCount(1, $transports->sent(self::ASYNC));
    }

    public function testResetDrainsTheQueue(): void
    {
        $transport = new InMemoryTransport();
        $transport->send(new Envelope(new stdClass()));

        $transports = $this->transportsWith($transport);
        $transports->reset(self::ASYNC);

        $this->assertSame([], $transports->pending(self::ASYNC));
    }

    private function transportsWith(object $transport): MessengerTransports
    {
        $container = new Container();
        $container->set(self::ASYNC_SERVICE, $transport);

        return new MessengerTransports($container);
    }
}
