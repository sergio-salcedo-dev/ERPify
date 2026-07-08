<?php

declare(strict_types=1);

namespace Erpify\Organization\Organization\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;

/**
 * Raised when provisioning is attempted against an installation that already has its organization —
 * the "one organization per installation" rule (tenancy is modelled but not yet operated).
 *
 * Marker-less by design: it guards a CLI bootstrap step, never an HTTP surface in this slice; an RFC 9457
 * marker is wired if and when a request path ever provisions organizations.
 */
final class OrganizationAlreadyProvisioned extends DomainException
{
    public function __construct()
    {
        parent::__construct(
            type: 'organization-already-provisioned',
            title: 'This installation already has an organization.',
        );
    }
}
