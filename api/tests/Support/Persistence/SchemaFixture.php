<?php

declare(strict_types=1);

namespace Erpify\Tests\Support\Persistence;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Sequence;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;

/**
 * Minimal schema objects a schema listener is dispatched over — the neighbours it must leave alone and the
 * tables it attaches to — built through the DBAL 4.5 editors, since the suite fails on a deprecation.
 *
 * Every column is a GUID: the fixtures stand in for tables whose shape the listener under test never reads,
 * so their types carry no assertion.
 *
 * @internal test support
 */
final class SchemaFixture
{
    /**
     * @param non-empty-string $name
     * @param non-empty-string $column
     * @param non-empty-string ...$otherColumns
     */
    public static function table(string $name, string $column, string ...$otherColumns): Table
    {
        return Table::editor()
            ->setUnquotedName($name)
            ->setColumns(...self::guidColumns($column, ...$otherColumns))
            ->create()
        ;
    }

    /**
     * @param non-empty-string $name
     * @param non-empty-string $primaryKey
     * @param non-empty-string ...$otherColumns
     */
    public static function tableWithPrimaryKey(string $name, string $primaryKey, string ...$otherColumns): Table
    {
        return self::table($name, $primaryKey, ...$otherColumns)
            ->edit()
            ->setPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames($primaryKey)->create())
            ->create()
        ;
    }

    /**
     * @param non-empty-string $name
     * @param non-empty-string $column
     */
    public static function index(string $name, string $column): Index
    {
        return Index::editor()->setUnquotedName($name)->setUnquotedColumnNames($column)->create();
    }

    /**
     * A foreign key onto the referenced table's `id`.
     *
     * @param non-empty-string $name
     * @param non-empty-string $column
     * @param non-empty-string $referencedTable
     */
    public static function foreignKey(string $name, string $column, string $referencedTable): ForeignKeyConstraint
    {
        return ForeignKeyConstraint::editor()
            ->setUnquotedName($name)
            ->setUnquotedReferencingColumnNames($column)
            ->setUnquotedReferencedTableName($referencedTable)
            ->setUnquotedReferencedColumnNames('id')
            ->create()
        ;
    }

    /**
     * @param non-empty-string $name
     * @param positive-int     $allocationSize
     */
    public static function sequence(string $name, int $allocationSize): Sequence
    {
        return Sequence::editor()->setUnquotedName($name)->setAllocationSize($allocationSize)->create();
    }

    /**
     * @param non-empty-string ...$names
     *
     * @return list<Column>
     */
    private static function guidColumns(string ...$names): array
    {
        return \array_map(
            static fn (string $name): Column => Column::editor()
                ->setUnquotedName($name)
                ->setTypeName(Types::GUID)
                ->create(),
            \array_values($names),
        );
    }
}
