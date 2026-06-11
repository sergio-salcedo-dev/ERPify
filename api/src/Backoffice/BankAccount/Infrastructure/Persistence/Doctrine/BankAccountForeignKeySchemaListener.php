<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Infrastructure\Persistence\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\ToolEvents;

/**
 * Re-injects the physical `bank_account.bank_id` → `bank.id` foreign key into Doctrine's in-memory
 * schema. BankAccount references Bank by id through a plain column rather than a mapped association, so
 * the ORM does not derive this FK from metadata — without this listener the schema diff would drop it.
 *
 * Adding the FK also creates its backing index under the same name (DBAL derives both names from the
 * table+column hash), so `make db.diff` stays empty with no recurring manual migration.
 */
#[AsDoctrineListener(event: ToolEvents::postGenerateSchema)]
final class BankAccountForeignKeySchemaListener
{
    private const string TABLE = 'bank_account';

    private const string REFERENCED_TABLE = 'bank';

    private const string COLUMN = 'bank_id';

    private const string FOREIGN_KEY = 'FK_53A23E0A11C8FB41';

    public function postGenerateSchema(GenerateSchemaEventArgs $args): void
    {
        $schema = $args->getSchema();

        if (!$schema->hasTable(self::TABLE) || !$schema->hasTable(self::REFERENCED_TABLE)) {
            return;
        }

        $table = $schema->getTable(self::TABLE);

        if ($table->hasForeignKey(self::FOREIGN_KEY)) {
            return;
        }

        $table->addForeignKeyConstraint(self::REFERENCED_TABLE, [self::COLUMN], ['id'], [], self::FOREIGN_KEY);
    }
}
