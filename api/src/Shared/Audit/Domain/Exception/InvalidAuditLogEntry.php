<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;

/**
 * Thrown when an {@see \Erpify\Shared\Audit\Application\AuditLogEntry} is built with an
 * invariant-breaking field: a blank `action`, or an `action`/`resourceType` longer than
 * the `audit_log` column width.
 *
 * Deliberately marker-less: the `action` is minted by the calling module as a constant, so
 * a blank or over-long value is a server-side programming fault, not bad client input.
 * Leaving it unmarked keeps it out of the RFC 9457 4xx mapping and surfaces it in Sentry as
 * an actionable error instead of a suppressed client error — the same reasoning as the
 * sibling {@see InvalidActorContext}.
 *
 * Only the field name, limit and measured length travel in `context`; the offending value
 * is never carried, since it may hold untrusted data.
 */
final class InvalidAuditLogEntry extends DomainException
{
    public const string TYPE = 'invalid-audit-log-entry';

    public static function actionMustNotBeEmpty(): self
    {
        return new self(
            type: self::TYPE,
            title: 'Audit log entry action must not be empty.',
        );
    }

    public static function fieldExceedsMaxLength(string $field, int $max, int $actual): self
    {
        return new self(
            type: self::TYPE,
            title: 'Audit log entry field exceeds its maximum length.',
            context: ['field' => $field, 'max' => $max, 'actual' => $actual],
        );
    }
}
