<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\BankAccount\Application;

use Override;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\RuntimeException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * {@see MessageBusInterface} that always fails to dispatch (a Messenger {@see RuntimeException}), so a
 * test can prove an access-audit dispatch failure does not bring down an otherwise-successful read.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.UnusedFormalParameter")
 */
final class ThrowingMessageBus implements MessageBusInterface
{
    /**
     * @param array<array-key, mixed> $stamps
     */
    #[Override]
    public function dispatch(object $message, array $stamps = []): Envelope
    {
        throw new RuntimeException('audit transport unavailable');
    }
}
