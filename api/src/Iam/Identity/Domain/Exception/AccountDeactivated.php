<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;
use Erpify\Shared\ErrorContract\Domain\Exception\Forbidden;

/**
 * The login admission wall for a `DEACTIVATED` identity: a terminal admission wall for a retired identity,
 * distinct by design from a reversible suspension.
 *
 * Overrides `type()` to the specific `account-deactivated` while the {@see Forbidden} marker keeps the 403
 * status — the same marker-plus-`type()` pattern {@see AccountSuspended} uses. A specific type lets a client
 * tell this business verdict apart from a generic infrastructural `forbidden` (an origin/CSRF rejection), which
 * shares the status. Carries no `AccessDeniedException` in its chain, so `UnauthenticatedAccessListener` leaves
 * it alone and the RFC 9457 pipeline emits the 403 through the marker branch.
 */
final class AccountDeactivated extends DomainException implements Forbidden
{
    public function __construct()
    {
        parent::__construct(
            type: 'account-deactivated',
            title: 'Your account is not active.',
        );
    }
}
