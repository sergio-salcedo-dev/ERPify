<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double;

use Override;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\StampInterface;
use Throwable;

/**
 * Spy {@see MessageBusInterface} recording every dispatched message, or — when constructed with a
 * failure — always throwing on dispatch, so one double covers both the activity success path and its
 * best-effort failure path.
 *
 * @internal
 */
final class RecordingMessageBus implements MessageBusInterface
{
    /** @var list<object> */
    public array $dispatchedMessages = [];

    public function __construct(private readonly ?Throwable $failure = null)
    {
    }

    /**
     * @param StampInterface[] $stamps
     */
    #[Override]
    public function dispatch(object $message, array $stamps = []): Envelope
    {
        if ($this->failure instanceof Throwable) {
            throw $this->failure;
        }

        $this->dispatchedMessages[] = $message;

        return $message instanceof Envelope ? $message->with(...$stamps) : new Envelope($message, $stamps);
    }
}
