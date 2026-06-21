<?php

declare(strict_types=1);

namespace Erpify\Shared\Uuid\Domain;

use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;
use Erpify\Shared\ErrorContract\Domain\Exception\InvalidInput;

/**
 * Raised by {@see Uuid::ensure()} when a value is not a well-formed RFC 4122 UUID.
 *
 * Carries the {@see InvalidInput} marker so the RFC 9457 pipeline maps it to a
 * 400 `invalid-input` ProblemDetails. The raw offending value is intentionally
 * not reflected into the title to avoid echoing unbounded client input.
 */
final class InvalidUuidException extends DomainException implements InvalidInput
{
    public function __construct()
    {
        parent::__construct(
            type: 'invalid-uuid',
            title: 'The provided value is not a valid UUID.',
        );
    }
}
