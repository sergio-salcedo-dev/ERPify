<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;
use Erpify\Shared\ErrorContract\Domain\Exception\Forbidden;

/**
 * The post-identity admission wall for a `SUSPENDED` identity: a SPECIFIC 403 that carries a real next step
 * (contact an administrator to restore access). Only a presenter who proved control of the identity meets it —
 * a valid reset or invitation token, a recovery secret, a live session — or a login whose status changed
 * between verification and the session mint; a password alone is refused earlier with the uniform 401.
 *
 * Overrides `type()` to the specific `account-suspended` while the {@see Forbidden} marker keeps the 403
 * status — the same marker-plus-`type()` pattern the `InvalidSearchCriteria` family uses. Carries no
 * `AccessDeniedException` in its chain, so `UnauthenticatedAccessListener` leaves it alone and the RFC 9457
 * pipeline emits the 403 through the marker branch.
 */
final class AccountSuspended extends DomainException implements Forbidden
{
    public function __construct()
    {
        parent::__construct(
            type: 'account-suspended',
            title: 'Your account has been suspended. Contact an administrator to restore access.',
        );
    }
}
