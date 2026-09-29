<?php

declare(strict_types=1);

namespace Erpify\Organization\Membership\Infrastructure\Persistence\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Tools\ToolEvents;
use Erpify\Shared\Persistence\Infrastructure\InjectedForeignKeySchemaListener;

/**
 * Re-injects the physical `membership.organization_id` → `organization.id` foreign key into Doctrine's
 * in-memory schema. Membership references Organization by id through a plain column rather than a mapped
 * association, so the ORM does not derive this FK from metadata — without this listener the schema diff
 * would never propose it and referential integrity would be unenforced.
 *
 * This FK is kept because both tables live in the same `Organization/` context (mirroring the blessed
 * intra-context `bank_account.bank_id` → `bank.id`). The other reference the aggregate carries,
 * `membership.user_id` → `identity_user.id`, deliberately has NO physical FK: it crosses a bounded context
 * (Organization → Iam), where isolation is by id, not by a schema-level coupling.
 */
#[AsDoctrineListener(event: ToolEvents::postGenerateSchema)]
final class MembershipOrganizationForeignKeySchemaListener extends InjectedForeignKeySchemaListener
{
    public function __construct()
    {
        parent::__construct(
            table: 'membership',
            column: 'organization_id',
            referencedTable: 'organization',
            foreignKey: 'fk_membership_organization',
        );
    }
}
