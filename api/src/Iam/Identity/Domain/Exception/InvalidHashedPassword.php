<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;

/**
 * Raised when a {@see \Erpify\Iam\Identity\Domain\HashedPassword} is built from an empty string.
 *
 * Marker-less by design: an empty hash is an internal integrity violation, not a client request error —
 * the hasher (Infrastructure) never yields an empty hash, so this can only surface from corrupt state,
 * which the RFC 9457 pipeline maps to a generic server fault rather than a 4xx.
 */
final class InvalidHashedPassword extends DomainException
{
    public function __construct()
    {
        parent::__construct(
            type: 'invalid-hashed-password',
            title: 'A hashed password cannot be empty.',
        );
    }
}
