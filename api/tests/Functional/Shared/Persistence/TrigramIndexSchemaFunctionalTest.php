<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * An index the mapping names `*_trgm` is a GIN `gin_trgm_ops` index in the migrated database, and every such
 * index in the database is one the mapping names that way.
 *
 * The mapping cannot say it itself: DBAL models neither an index's access method nor its operator class, so
 * `#[ORM\Index]` declares only the name and the columns and {@see MappedSchemaMatchesMigrationsTest} compares
 * only those. A migration that built the index as a B-tree, or forgot the operator class, would leave every
 * schema check green while each accent-insensitive CONTAINS over the column went back to a sequential scan.
 * The suffix is the declaration of intent, so the universe is derived from the mapping rather than listed.
 *
 * What a green does not prove: that a CONTAINS filter reads a column carrying such an index — that pairing
 * lives in the search field maps, which this does not read — nor that the planner chooses the index.
 *
 * @internal
 */
#[CoversNothing]
final class TrigramIndexSchemaFunctionalTest extends KernelTestCase
{
    private const string SUFFIX = '_trgm';

    #[Test]
    public function everyTrigramIndexTheMappingNamesIsAGinTrigramIndexInTheDatabase(): void
    {
        $declared = $this->declaredTrigramIndexes();

        $this->assertArrayHasKey(
            'idx_bank_account_holder_name_trgm',
            $declared,
            'The mapping walk found no trigram index on bank_account, which declares one: the sweep below '
            . 'would pass over an empty or partial universe.',
        );

        $migrated = $this->migratedTrigramIndexes();

        foreach ($declared as $name => $definition) {
            $this->assertSame($definition, $migrated[$name] ?? null, \sprintf(
                'The mapping declares "%s" as a trigram index, but the migrated database does not hold it as '
                . '`USING gin (<column> gin_trgm_ops)`. Build it that way in the migration — DBAL cannot.',
                $name,
            ));
        }

        $this->assertSame([], \array_keys(\array_diff_key($migrated, $declared)), \sprintf(
            'The database holds a GIN trigram index the mapping does not declare with the "%s" suffix. Declare '
            . 'it on the entity as `#[ORM\Index]` under a name ending in that suffix.',
            self::SUFFIX,
        ));
    }

    /**
     * @return array<string, string> index name => `<table> gin <column> gin_trgm_ops`
     */
    private function declaredTrigramIndexes(): array
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $declared = [];

        foreach ($entityManager->getMetadataFactory()->getAllMetadata() as $metadata) {
            foreach ($metadata->table['indexes'] ?? [] as $name => $index) {
                if (!\is_string($name) || !\str_ends_with($name, self::SUFFIX)) {
                    continue;
                }

                $this->assertIsArray($index);
                $columns = $index['columns'] ?? null;
                $this->assertIsArray($columns);
                $this->assertCount(1, $columns, \sprintf('Trigram index "%s" must cover exactly one column.', $name));
                $column = $columns[0] ?? null;
                $this->assertIsString($column);

                $declared[$name] = \sprintf('%s gin %s gin_trgm_ops', $metadata->getTableName(), $column);
            }
        }

        return $declared;
    }

    /**
     * @return array<string, string> index name => `<table> <access method> <column> <operator class>`
     */
    private function migratedTrigramIndexes(): array
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        $this->assertInstanceOf(Connection::class, $connection);

        $rows = $connection->fetchAllKeyValue(
            "SELECT c.relname, t.relname || ' ' || am.amname || ' ' || a.attname || ' ' || oc.opcname "
            . 'FROM pg_index i '
            . 'JOIN pg_class c ON c.oid = i.indexrelid '
            . 'JOIN pg_class t ON t.oid = i.indrelid '
            . 'JOIN pg_am am ON am.oid = c.relam '
            . 'JOIN pg_opclass oc ON oc.oid = i.indclass[0] '
            . 'LEFT JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = i.indkey[0] '
            . "WHERE oc.opcname IN ('gin_trgm_ops', 'gist_trgm_ops') OR c.relname LIKE '%\\_trgm'",
        );

        $migrated = [];

        foreach ($rows as $name => $definition) {
            $this->assertIsString($name);
            $this->assertIsString($definition);
            $migrated[$name] = $definition;
        }

        return $migrated;
    }
}
