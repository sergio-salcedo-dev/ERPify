<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Event\Infrastructure\Bus;

use Override;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Spy {@see MessageBusInterface} capturing every dispatched message, so the adapter test can assert
 * each event is forwarded, in order.
 *
 * @internal
 */
final class RecordingMessageBus implements MessageBusInterface
{
    /** @var list<object> */
    public array $dispatchedMessages = [];

    /**
     * @param StampInterface[] $stamps
     */
    #[Override]
    public function dispatch(object $message, array $stamps = []): Envelope
    {
        $this->dispatchedMessages[] = $message;

        return $message instanceof Envelope ? $message->with(...$stamps) : new Envelope($message, $stamps);
    }
}
