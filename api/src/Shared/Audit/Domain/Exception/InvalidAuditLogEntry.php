<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Domain\Exception;

use Erpify\Shared\Audit\Domain\AuditMetadataKey;
use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;

/**
 * Thrown when an {@see \Erpify\Shared\Audit\Application\AuditLogEntry} is built with an
 * invariant-breaking field: a blank `action` or `resourceType`, a non-UUID `resourceId` (the
 * `audit_log.resource_id` column is a `uuid`), an `action`/`resourceType` longer than the
 * `audit_log` column width, a `metadata` key outside {@see AuditMetadataKey} or a
 * value outside its shape, a key reserved to `change` entries on another level, or a `metadata` larger than its
 * bound.
 *
 * Deliberately marker-less: the `action` is minted by the calling module as a constant, so
 * a blank or over-long value is a server-side programming fault, not bad client input.
 * Leaving it unmarked keeps it out of the RFC 9457 4xx mapping and surfaces it in Sentry as
 * an actionable error instead of a suppressed client error — the same reasoning as the
 * sibling {@see InvalidActorContext}.
 *
 * Only the field name, limit and measured length travel in `context`; the offending value
 * is never carried, since it may hold untrusted data. An unknown `metadata` key travels only while it is
 * shaped like an identifier: a key is a literal in code today, and one that is not is exactly the case where
 * it might have come from somewhere else.
 */
final class InvalidAuditLogEntry extends DomainException
{
    public const string TYPE = 'invalid-audit-log-entry';

    private const string IDENTIFIER_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]{0,63}$/';

    public static function actionMustNotBeEmpty(): self
    {
        return new self(
            type: self::TYPE,
            title: 'Audit log entry action must not be empty.',
        );
    }

    public static function resourceTypeMustNotBeEmpty(): self
    {
        return new self(
            type: self::TYPE,
            title: 'Audit log entry resource type must not be empty.',
        );
    }

    public static function resourceIdMustBeUuid(): self
    {
        return new self(
            type: self::TYPE,
            title: 'Audit log entry resource id must be a UUID.',
            context: ['field' => 'resourceId'],
        );
    }

    public static function reservedToChangeEntries(AuditMetadataKey $key, string $level): self
    {
        return new self(
            type: self::TYPE,
            title: \sprintf('Audit log entry metadata.%s is reserved for change-level entries.', $key->value),
            context: ['field' => 'metadata.' . $key->value, 'level' => $level],
        );
    }

    public static function unknownMetadataKey(int|string $key): self
    {
        $printable = \is_string($key) && 1 === \preg_match(self::IDENTIFIER_PATTERN, $key)
            ? $key
            : '<not an identifier>';

        return new self(
            type: self::TYPE,
            title: 'Audit log entry metadata carries a key outside the audit metadata vocabulary.',
            context: ['field' => 'metadata', 'key' => $printable],
        );
    }

    public static function metadataValueOutOfShape(AuditMetadataKey $key): self
    {
        return new self(
            type: self::TYPE,
            title: 'Audit log entry metadata value does not have the shape its key declares.',
            context: ['field' => 'metadata.' . $key->value, 'shape' => $key->shape()->name],
        );
    }

    public static function metadataExceedsMaxSize(int $max, int $actual): self
    {
        return new self(
            type: self::TYPE,
            title: 'Audit log entry metadata exceeds its maximum encoded size.',
            context: ['field' => 'metadata', 'max' => $max, 'actual' => $actual],
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
