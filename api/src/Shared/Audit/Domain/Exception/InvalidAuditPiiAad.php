<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;

/**
 * Thrown when an {@see \Erpify\Shared\Audit\Application\AuditPiiAad} cannot be built because a part is
 * empty or contains the `|` separator that joins them — which would let two distinct slots collide onto
 * one AAD and break its injectivity. Marker-less: the parts are a field name and an audit-log id derived
 * from trusted internal data, so a malformed AAD is a wiring fault, not client input. The offending value
 * is never carried.
 */
final class InvalidAuditPiiAad extends DomainException
{
    public const string TYPE = 'invalid-audit-pii-aad';

    public static function malformed(): self
    {
        return new self(
            type: self::TYPE,
            title: 'Audit PII AAD parts must be non-empty and free of the "|" separator.',
        );
    }
}
