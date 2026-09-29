<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Infrastructure\Persistence;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ColumnEditor;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\ToolEvents;

/**
 * Injects the singleton `bank_count` read-model table into Doctrine's in-memory schema. Written
 * through plain DBAL ({@see \Erpify\Backoffice\Bank\Infrastructure\Projection\DbalBankCountReadModel}),
 * no ORM entity. The singleton `CHECK (id = 1)` of the ADR is not modelled (Doctrine's schema
 * abstraction cannot express it); the invariant is enforced by the read model always upserting `id = 1`.
 */
#[AsDoctrineListener(event: ToolEvents::postGenerateSchema)]
final class BankCountSchemaListener
{
    private const string TABLE = 'bank_count';

    public function postGenerateSchema(GenerateSchemaEventArgs $args): void
    {
        $schema = $args->getSchema();

        if ($schema->hasTable(self::TABLE)) {
            return;
        }

        $table = Table::editor()
            ->setUnquotedName(self::TABLE)
            ->setColumns(
                $this->column('id', Types::SMALLINT)->setDefaultValue(1)->create(),
                $this->column('total', Types::INTEGER)->setDefaultValue(0)->create(),
                $this->column('updated_at', Types::DATETIMETZ_IMMUTABLE)->create(),
            )
            ->setPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create())
            ->create()
        ;

        $args->setSchema($schema->edit()->addTable($table)->create());
    }

    /** @param non-empty-string $name */
    private function column(string $name, string $type): ColumnEditor
    {
        return Column::editor()->setUnquotedName($name)->setTypeName($type);
    }
}
