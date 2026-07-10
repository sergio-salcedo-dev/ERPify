<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;
use Erpify\Shared\ErrorContract\Domain\Exception\Forbidden;

/**
 * The login admission wall for a `DEACTIVATED` identity: a GENERIC 403 with no actionable next step — a
 * retired identity, distinct by design from a reversible suspension.
 *
 * The empty `type` falls through to the {@see Forbidden} marker default (`forbidden`), so it is deliberately
 * indistinguishable on the wire from any other generic denial: the specificity boundary is identity proof,
 * and there is nothing the holder can do about a deactivation from the login screen.
 */
final class AccountDeactivated extends DomainException implements Forbidden
{
    public function __construct()
    {
        parent::__construct(
            type: '',
            title: 'Your account is not active.',
        );
    }
}
