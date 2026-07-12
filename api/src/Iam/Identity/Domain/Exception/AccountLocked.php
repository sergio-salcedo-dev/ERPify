<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;
use Erpify\Shared\ErrorContract\Domain\Exception\Forbidden;

/**
 * The login admission wall for an identity whose lockout is still in force: a SPECIFIC 403 that carries a real
 * next step (reset the password to regain access). Orthogonal to {@see AccountSuspended} — the lock is a
 * transient, time-boxed state of the authentication machine, not a lifecycle status.
 *
 * Overrides `type()` to the specific `account-locked` while the {@see Forbidden} marker keeps the 403 status —
 * the same marker-plus-`type()` pattern {@see AccountSuspended} uses. Carries no `AccessDeniedException` in its
 * chain, so `UnauthenticatedAccessListener` leaves it alone and the RFC 9457 pipeline emits the 403 through the
 * marker branch.
 */
final class AccountLocked extends DomainException implements Forbidden
{
    public function __construct()
    {
        parent::__construct(
            type: 'account-locked',
            title: 'Your account is temporarily locked after too many failed attempts. '
                . 'Reset your password to regain access.',
        );
    }
}
