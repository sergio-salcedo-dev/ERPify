<?php

declare(strict_types=1);

namespace Erpify\Shared\Persistence\Infrastructure;

use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\TableEditor;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;

/**
 * Re-injects a physical foreign key that no ORM association derives: an aggregate referencing another by
 * id holds a plain column, so without this the schema diff would drop the constraint (or never propose it).
 * A subclass declares the `postGenerateSchema` listener attribute, names the constraint and says why the
 * physical coupling is kept.
 *
 * Adding the FK also creates its backing index under the name DBAL derives from the table+column hash, so
 * `make db.diff` stays empty with no recurring manual migration. The referenced column is always `id`.
 */
abstract class InjectedForeignKeySchemaListener
{
    /**
     * @param non-empty-string $table
     * @param non-empty-string $column
     * @param non-empty-string $referencedTable
     * @param non-empty-string $foreignKey
     */
    protected function __construct(
        private readonly string $table,
        private readonly string $column,
        private readonly string $referencedTable,
        private readonly string $foreignKey,
    ) {
    }

    final public function postGenerateSchema(GenerateSchemaEventArgs $args): void
    {
        $schema = $args->getSchema();

        if (!$schema->hasTable($this->table) || !$schema->hasTable($this->referencedTable)) {
            return;
        }

        if ($schema->getTable($this->table)->hasForeignKey($this->foreignKey)) {
            return;
        }

        $foreignKey = ForeignKeyConstraint::editor()
            ->setUnquotedName($this->foreignKey)
            ->setUnquotedReferencingColumnNames($this->column)
            ->setUnquotedReferencedTableName($this->referencedTable)
            ->setUnquotedReferencedColumnNames('id')
            ->create()
        ;

        $args->setSchema(
            $schema->edit()
                ->modifyTableByUnquotedName(
                    $this->table,
                    static function (TableEditor $table) use ($foreignKey): void {
                        $table->addForeignKeyConstraint($foreignKey);
                    },
                )
                ->create(),
        );
    }
}
