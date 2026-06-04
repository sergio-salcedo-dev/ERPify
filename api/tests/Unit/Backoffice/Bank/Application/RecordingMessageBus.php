<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\Bank\Application;

use Override;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Spy {@see MessageBusInterface} that captures every dispatched message, so a test
 * can assert no domain event escapes when the deletion is rejected.
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
