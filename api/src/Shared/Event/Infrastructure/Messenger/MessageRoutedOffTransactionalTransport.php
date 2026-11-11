<?php

declare(strict_types=1);

namespace Erpify\Shared\Event\Infrastructure\Messenger;

use RuntimeException;

/**
 * A message that must commit with the business write was refused because Messenger routes it to a transport
 * outside the admitted set: `async`, validated as the Doctrine transport on the caller's connection, and `sync`,
 * which dispatches again in process. Distinct from {@see AsyncTransportNotTransactional}: the transport is fine,
 * the routing is not. Names the message class and the transport aliases only, never a DSN.
 */
final class MessageRoutedOffTransactionalTransport extends RuntimeException
{
    /**
     * @param non-empty-list<string> $aliases the sender aliases outside the admitted set
     */
    public function __construct(string $messageClass, array $aliases)
    {
        parent::__construct(\sprintf(
            'Refusing to publish %s: it is routed to %s, and the only transports admitted for it are async and '
            . 'sync. Route it (or address it by stamp) only to async and/or sync, or leave it unrouted so it is '
            . 'handled in process.',
            $messageClass,
            \implode(', ', $aliases),
        ));
    }
}
