<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Messenger\Maintenance;

/**
 * Scheduler tick that triggers the session retention sweep. Carries no parameters, and that DIVERGES from the
 * two other prunes in this tree: `PruneAuditLogMessage` and `PruneHandledDomainEventsMessage` both carry their
 * retention windows and hand them to a pruner that takes them as arguments.
 *
 * The windows live in {@see \Erpify\Iam\Session\Application\PruneRetiredSessions} instead, because this sweep
 * has two entry points — this tick and the `iam:session:prune` command — and a window travelling on the
 * message would be a policy the CLI arm either restates or omits. One owner is what keeps the two arms from
 * enforcing different retention.
 *
 * The cost of that choice, stated so it is a trade and not an oversight: the windows cannot be varied per
 * entry point or per environment without changing code. If that is ever wanted, the payload is where it goes,
 * and the command needs the same options in the same breath.
 */
final readonly class PruneRetiredSessionsMessage
{
}
