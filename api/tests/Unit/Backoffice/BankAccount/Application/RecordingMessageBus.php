<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\BankAccount\Application;

use Override;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Spy {@see MessageBusInterface} capturing every dispatched message, so a test can assert exactly one
 * access-audit message is emitted on a successful read (and none on a rejected one).
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
