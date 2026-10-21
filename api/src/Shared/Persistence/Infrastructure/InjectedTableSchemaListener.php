<?php

declare(strict_types=1);

namespace Erpify\Shared\Persistence\Infrastructure;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ColumnEditor;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\IndexEditor;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\TableEditor;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;

/**
 * Keeps a table written through plain DBAL — and therefore mapped by no ORM entity — visible to Doctrine's
 * schema tool, so `make db.diff` neither drops it nor loses track of its shape. A subclass declares the
 * `postGenerateSchema` listener attribute and describes the table in {@see define()}; this class owns the
 * injection, which must hand back a new schema because DBAL 4.5 deprecates mutating one in place.
 *
 * A table the schema already holds is left untouched, so a mapping that starts owning it wins.
 */
abstract class InjectedTableSchemaListener
{
    /** @param non-empty-string $table */
    protected function __construct(private readonly string $table)
    {
    }

    final public function postGenerateSchema(GenerateSchemaEventArgs $args): void
    {
        $schema = $args->getSchema();

        if ($schema->hasTable($this->table)) {
            return;
        }

        $table = $this->define(Table::editor()->setUnquotedName($this->table))->create();

        $args->setSchema($schema->edit()->addTable($table)->create());
    }

    /** Declares the columns, primary key and indexes of the table on an editor already carrying its name. */
    abstract protected function define(TableEditor $table): TableEditor;

    /** @param non-empty-string $name */
    protected function column(string $name, string $type): ColumnEditor
    {
        return Column::editor()->setUnquotedName($name)->setTypeName($type);
    }

    /**
     * @param non-empty-string $name
     * @param non-empty-string $firstColumn
     * @param non-empty-string ...$otherColumns
     */
    protected function index(string $name, string $firstColumn, string ...$otherColumns): IndexEditor
    {
        return Index::editor()->setUnquotedName($name)->setUnquotedColumnNames($firstColumn, ...$otherColumns);
    }
}
