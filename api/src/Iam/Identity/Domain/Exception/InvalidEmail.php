<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;

/**
 * Raised when an {@see \Erpify\Iam\Identity\Domain\Email} is built from a blank value.
 *
 * Marker-less by design: the aggregate's own invariant (an identifier is never blank) — RFC format
 * validity is a separate, application-boundary concern handled by `#[Assert\Email]`.
 */
final class InvalidEmail extends DomainException
{
    public function __construct()
    {
        parent::__construct(
            type: 'invalid-email',
            title: 'An email cannot be blank.',
        );
    }
}
