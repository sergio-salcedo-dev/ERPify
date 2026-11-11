<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\Conflict;
use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;

/**
 * The idempotency key was already used by this caller with different parameters (size, digest or retention
 * terms). A replay must repeat the original declaration exactly; answering the earlier upload instead would
 * silently file something the caller did not ask for. Names neither parameter.
 */
final class IdempotencyKeyConflict extends DomainException implements Conflict
{
    public const string TYPE = 'idempotency-key-conflict';

    public static function create(): self
    {
        return new self(
            type: self::TYPE,
            title: 'The idempotency key was already used with different parameters.',
        );
    }
}
