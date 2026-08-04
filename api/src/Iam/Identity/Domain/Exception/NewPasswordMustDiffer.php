<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;
use Erpify\Shared\ErrorContract\Domain\Exception\InvariantViolation;

/**
 * The self-service credential change refused because the proposed password is the one already stored. A
 * well-formed request that the domain cannot honour — replacing a credential with itself would emit a
 * "password changed" fact, tear down every other session and mail a security notice for a change that never
 * happened — so it is an {@see InvariantViolation} (422), not a validation failure the wire layer could catch:
 * the rule needs the stored hash, which only the aggregate's transaction holds.
 *
 * Overrides `type()` to the specific `new-password-must-differ` so the PWA can hang the message on the
 * `newPassword` field; the marker keeps the status, so no new marker interface joins the contract.
 */
final class NewPasswordMustDiffer extends DomainException implements InvariantViolation
{
    public function __construct()
    {
        parent::__construct(
            type: 'new-password-must-differ',
            title: 'Your new password must be different from your current password.',
        );
    }
}
