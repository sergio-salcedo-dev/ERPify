<?php

declare(strict_types=1);

namespace Erpify\Organization\Membership\Infrastructure\Persistence\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\TableEditor;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\ToolEvents;

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
final class MembershipOrganizationForeignKeySchemaListener
{
    private const string TABLE = 'membership';

    private const string REFERENCED_TABLE = 'organization';

    private const string COLUMN = 'organization_id';

    private const string FOREIGN_KEY = 'fk_membership_organization';

    public function postGenerateSchema(GenerateSchemaEventArgs $args): void
    {
        $schema = $args->getSchema();

        if (!$schema->hasTable(self::TABLE) || !$schema->hasTable(self::REFERENCED_TABLE)) {
            return;
        }

        if ($schema->getTable(self::TABLE)->hasForeignKey(self::FOREIGN_KEY)) {
            return;
        }

        $foreignKey = ForeignKeyConstraint::editor()
            ->setUnquotedName(self::FOREIGN_KEY)
            ->setUnquotedReferencingColumnNames(self::COLUMN)
            ->setUnquotedReferencedTableName(self::REFERENCED_TABLE)
            ->setUnquotedReferencedColumnNames('id')
            ->create()
        ;

        $args->setSchema(
            $schema->edit()
                ->modifyTableByUnquotedName(
                    self::TABLE,
                    static function (TableEditor $table) use ($foreignKey): void {
                        $table->addForeignKeyConstraint($foreignKey);
                    },
                )
                ->create(),
        );
    }
}
