<?php

declare(strict_types=1);

namespace Erpify\Tests\Support\Persistence;

use Doctrine\DBAL\Schema\Index\IndexedColumn;
use Doctrine\DBAL\Schema\Index\IndexType;
use Doctrine\DBAL\Schema\Name\UnqualifiedName;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Table;
use PHPUnit\Framework\Assert;

/**
 * Read-side projections of a DBAL {@see Table} into plain arrays, so a schema listener's output is pinned by
 * one `assertSame` per facet — columns, secondary indexes, primary key — and a mismatch diffs as data.
 *
 * Only accessors DBAL 4.5 does not deprecate are read: the suite fails on a deprecation, so a projection
 * built on a deprecated one would red every test using it rather than the one whose shape changed.
 *
 * @phpstan-import-type Shape from ColumnShape
 *
 * @phpstan-type IndexShape array{columns: list<string>, unique: bool}
 *
 * @internal test support
 */
final class SchemaShape
{
    /** @return array<string, Shape> keyed by column name, in declaration order */
    public static function columnsOf(Table $table): array
    {
        $columns = [];

        foreach ($table->getColumns() as $column) {
            $columns[$column->getObjectName()->toString()] = ColumnShape::read($column)->toArray();
        }

        return $columns;
    }

    /**
     * The secondary indexes, keyed by name and sorted. DBAL also lists a primary key as an index under
     * `primary`, which {@see primaryKeyOf()} pins on its own — so that entry is dropped only when the table
     * really carries a primary key constraint, and an index merely named so stays visible.
     *
     * @return array<string, IndexShape>
     */
    public static function indexesOf(Table $table): array
    {
        $hasPrimaryKey = $table->getPrimaryKeyConstraint() instanceof PrimaryKeyConstraint;
        $indexes = [];

        foreach ($table->getIndexes() as $key => $index) {
            if ($hasPrimaryKey && 'primary' === $key) {
                continue;
            }

            $indexes[$index->getObjectName()->toString()] = [
                'columns' => \array_map(
                    static fn (IndexedColumn $column): string => $column->getColumnName()->toString(),
                    $index->getIndexedColumns(),
                ),
                'unique' => IndexType::UNIQUE === $index->getType(),
            ];
        }

        \ksort($indexes);

        return $indexes;
    }

    /** @return list<string> the primary key's columns, in order; fails the test when the table has none */
    public static function primaryKeyOf(Table $table): array
    {
        $primaryKey = $table->getPrimaryKeyConstraint();
        Assert::assertInstanceOf(PrimaryKeyConstraint::class, $primaryKey);

        return self::names($primaryKey->getColumnNames());
    }

    /**
     * @param list<UnqualifiedName> $names
     *
     * @return list<string>
     */
    public static function names(array $names): array
    {
        return \array_map(static fn (UnqualifiedName $name): string => $name->toString(), $names);
    }

    /** @return array{columns: list<string>, unique: false} */
    public static function regularIndex(string ...$columns): array
    {
        return ['columns' => \array_values($columns), 'unique' => false];
    }

    /** @return array{columns: list<string>, unique: true} */
    public static function uniqueIndex(string ...$columns): array
    {
        return ['columns' => \array_values($columns), 'unique' => true];
    }
}
