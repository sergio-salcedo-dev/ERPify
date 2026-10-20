<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Persistence\Fixtures;

use Closure;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * A bus that hands every dispatched message to one callback and returns it unchanged — the least a `Worker` needs
 * to run a handler, without the routing and middleware of the application's buses.
 *
 * @internal
 */
final readonly class CallbackMessageBus implements MessageBusInterface
{
    public function __construct(private Closure $handle)
    {
    }

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        ($this->handle)();

        return Envelope::wrap($message, $stamps);
    }
}
