<?php

declare(strict_types=1);

namespace Erpify\Shared\Event\Infrastructure\Messenger;

use LogicException;

/**
 * A message that must commit with the business write was dispatched with no transaction open on the
 * application connection.
 */
final class PublishedOutsideTransaction extends LogicException
{
    public function __construct(string $messageClass)
    {
        parent::__construct(\sprintf(
            'Refusing to publish %s outside a transaction: its event_store row and its outbox row must commit '
            . 'with the business write, so publish inside TransactionManager::transactional().',
            $messageClass,
        ));
    }
}
