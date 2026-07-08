<?php

declare(strict_types=1);

namespace Erpify\Organization\Membership\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;

/**
 * Raised when a membership is granted before the installation's organization exists — a member cannot
 * belong to nothing. In the bootstrap sequence the organization is provisioned first.
 *
 * Marker-less by design: it guards a CLI bootstrap step, not an HTTP surface in this slice.
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
