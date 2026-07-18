<?php

declare(strict_types=1);

namespace Erpify\Organization\Membership\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;

/**
 * Raised when a membership is granted before the installation's organization exists — a member cannot belong
 * to nothing. The organization is provisioned first in the bootstrap sequence.
 *
 * Marker-less by design → HTTP 500: a missing installation organization is a broken server invariant, never a
 * client-correctable 4xx. It is unreachable on the gated invitation/membership path — a gated ADMIN session
 * implies the single installation organization already exists — so a 4xx marker would disguise a server fault
 * as a client error.
 */
final class OrganizationNotProvisioned extends DomainException
{
    public function __construct()
    {
        parent::__construct(
            type: 'organization-not-provisioned',
            title: 'The installation has no organization yet; provision it before granting a membership.',
        );
    }
}
