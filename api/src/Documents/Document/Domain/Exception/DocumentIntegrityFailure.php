<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;
use Throwable;

/**
 * A `Ready` document whose sealed original cannot be proved to be the byte that was filed: the object is missing, a
 * chunk fails authentication, or the digest or size no longer match the row. It carries NO marker on purpose, so it
 * maps to 5xx and reaches the error tracker — the one read-side outcome that is a fault of the deployment and never a
 * client mistake. The sealed layer's exception rides along as `previous` for the tracker; it is never rendered.
 */
final class DocumentIntegrityFailure extends DomainException
{
    public const string TYPE = 'document-integrity-failure';

    public static function create(?Throwable $cause = null): self
    {
        return new self(
            type: self::TYPE,
            title: 'The stored document could not be verified.',
            previous: $cause,
        );
    }
}
