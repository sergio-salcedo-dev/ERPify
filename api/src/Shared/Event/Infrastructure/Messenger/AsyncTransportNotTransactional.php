<?php

declare(strict_types=1);

namespace Erpify\Shared\Event\Infrastructure\Messenger;

use RuntimeException;

/**
 * A message routed to `async` was refused because `async` would publish outside the caller's transaction.
 * The diagnosis is {@see AsyncTransportValidator::problem()} — class names only, never the DSN.
 */
final class AsyncTransportNotTransactional extends RuntimeException
{
    public function __construct(string $messageClass, string $problem)
    {
        parent::__construct(\sprintf(
            'Refusing to publish %s: %s Fix MESSENGER_TRANSPORT_DSN for this service '
            . '(it must be a doctrine:// DSN on the connection the application writes through).',
            $messageClass,
            $problem,
        ));
    }
}
