<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;
use Throwable;

/**
 * A second-factor secret that cannot be opened: its key is gone while its row survives, or the ciphertext no
 * longer authenticates under its scope and its identity. Both are faults of the deployment and never a client
 * mistake, because no path destroys a factor's key while its row survives — the identity erasure deletes the row
 * and destroys the key in one transaction. So it carries NO marker on purpose: it maps to 500 and reaches the
 * error tracker. The sealed layer's exception rides along as `previous` for the tracker and is never rendered;
 * nothing about the scope or the identity is put in the response.
 */
final class SecondFactorIntegrityFailure extends DomainException
{
    public const string TYPE = 'second-factor-integrity-failure';

    public static function create(Throwable $cause): self
    {
        return new self(
            type: self::TYPE,
            title: 'The stored two-step verification secret could not be read.',
            previous: $cause,
        );
    }
}
