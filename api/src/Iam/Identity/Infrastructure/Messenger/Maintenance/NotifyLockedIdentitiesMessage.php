<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Messenger\Maintenance;

/**
 * Scheduler tick that triggers the lockout-notification sweep. **Empty by design, and the emptiness is the
 * privacy control rather than a simplification.**.
 *
 * The obvious alternative — one message per locked identity, carrying the id or the address — would put a
 * person's identifier into `messenger_messages` and, on a failure, into `failed`: Doctrine tables with no
 * TTL, no prune and no erasure path reaching them, so a queued id would outlive the erasure the application
 * had already confirmed to its subject. `api/.persistent-transport-policy` would not catch it either — a
 * scheduler message is not a domain event, which that registry says in its own list of blind spots. Keeping
 * the payload empty means the sweep resolves its subjects inside the handler and nothing about a person is
 * ever written to a transport.
 */
final readonly class NotifyLockedIdentitiesMessage
{
}
