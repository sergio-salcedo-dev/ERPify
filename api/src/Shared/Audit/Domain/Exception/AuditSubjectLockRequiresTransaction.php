<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;

/**
 * Thrown when the audit subject row lock is asked for outside a transaction.
 *
 * The failure it replaces is the silent one. Postgres releases a `FOR UPDATE` at the end of the statement
 * that took it, so a caller with no transaction open gets the rows, gets no error, and holds nothing by the
 * time the anonymisers run — the deadlock the lock exists to prevent is back, and every gate is green. The
 * contract cannot express "you must be in a transaction" in the type system, so it is checked where the
 * connection is at hand.
 *
 * Deliberately marker-less, like its siblings in this namespace: no client input reaches this decision, so a
 * breach is a wiring fault in our own code. Leaving it unmarked keeps it out of the RFC 9457 4xx mapping and
 * surfaces it in Sentry as an actionable error rather than telling a caller they did something wrong.
 */
final class AuditSubjectLockRequiresTransaction extends DomainException
{
    public const string TYPE = 'audit-subject-lock-requires-transaction';

    public static function forSubject(string $resourceType): self
    {
        return new self(
            type: self::TYPE,
            title: 'The audit subject row lock must be taken inside a transaction.',
            context: ['resourceType' => $resourceType],
        );
    }
}
