<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Shared\Infrastructure\Persistence\Doctrine\Search\SortFieldMap;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * SHAPE gate for the keyset order-stability property (AR13: property > form > snapshot). This test
 * is subordinate to {@see KeysetOrderStabilityPropertyTest}: it never executes a search and never
 * asserts a result set. Instead, for every sortable field of the Bank repository's
 * {@see SortFieldMap}, it reads Doctrine {@see ClassMetadata} and the live Postgres schema and
 * asserts the structural precondition that *makes* a total order achievable — so a future schema
 * edit that drops a composite index or a `COLLATE "C"` is caught here as a contract break, not as a
 * flaky ordering failure downstream.
 *
 * The order-stability property, per sort column:
 *  - A UNIQUE column already yields a total order from its single-column unique index — no composite
 *    needed (`name_normalized`, `short_name`).
 *  - A non-unique sortable column needs a composite `(column, id)` index so the id tie-break resolves
 *    duplicate sort keys under one index walk (`created_at` → `idx_bank_created_at_id`, `updated_at`
 *    → `idx_bank_updated_at_id`).
 *  - In both cases the column is `nullable: false` (NULLs would break a strict keyset boundary).
 *  - Every sortable TEXT column declares `COLLATE "C"` so byte-wise ordering matches the keyset
 *    oracle independently of the database's locale collation.
 *
 * These hold after migration {@see \DoctrineMigrations\Version20260610195734} (already applied).
 *
 * @internal
 */
#[CoversClass(SortFieldMap::class)]
final class SortFieldMapIndexContractTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    private Connection $connection;

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $this->entityManager = $entityManager;
        $this->connection = $entityManager->getConnection();
    }

    #[Test]
    #[DataProvider('provideEverySortableColumnIsNotNullableCases')]
    public function everySortableColumnIsNotNullable(string $entityField): void
    {
        $nullable = $this->bankMetadata()->getFieldMapping($entityField)->nullable;

        // ORM 3 models a non-nullable column as null (the attribute was never set), so treat
        // null as false — a NULL-able sort key would break the strict keyset boundary.
        $this->assertNotTrue($nullable, \sprintf('Sort column for "%s" must be NOT NULL.', $entityField));
    }

    /**
     * Locks the field set under contract to the Bank repository's sortFieldMap (PUBLIC name → entity
     * field). If a sortable field is added or renamed, this provider must move with it — that is the
     * point: it keeps the per-field assertions below exhaustive over the live allow-list.
     *
     * @return iterable<string, array{string}>
     */
    public static function provideEverySortableColumnIsNotNullableCases(): iterable
    {
        yield 'name → nameNormalized' => ['nameNormalized'];
        yield 'shortName → shortName' => ['shortName'];
        yield 'createdAt → createdAt' => ['createdAt'];
        yield 'updatedAt → updatedAt' => ['updatedAt'];
    }

    #[Test]
    #[DataProvider('provideItRequiresACompositeIndexForEachNonUniqueSortableColumnCases')]
    public function itRequiresACompositeIndexForEachNonUniqueSortableColumn(string $column): void
    {
        $found = \array_any(
            $this->bankIndexDefinitionsFor(),
            static fn (string $indexdef): bool => \str_contains($indexdef, \sprintf('(%s, id)', $column)),
        );
        $this->assertTrue(
            $found,
            \sprintf('A composite index (%s, id) must exist to make the non-unique sort key totally ordered.', $column),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideItRequiresACompositeIndexForEachNonUniqueSortableColumnCases(): iterable
    {
        yield 'created_at needs (created_at, id)' => ['created_at'];
        yield 'updated_at needs (updated_at, id)' => ['updated_at'];
    }

    #[Test]
    #[DataProvider('provideAUniqueSortableColumnNeedsNoCompositeIndexCases')]
    public function aUniqueSortableColumnNeedsNoCompositeIndex(string $column): void
    {
        $hasSingleColumnUnique = \array_any(
            $this->bankIndexDefinitionsFor(),
            static fn (string $indexdef): bool => \str_contains($indexdef, 'UNIQUE INDEX')
                && \str_contains($indexdef, \sprintf('(%s)', $column)),
        );
        $this->assertTrue(
            $hasSingleColumnUnique,
            \sprintf('A single-column UNIQUE index on (%s) already satisfies order stability.', $column),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideAUniqueSortableColumnNeedsNoCompositeIndexCases(): iterable
    {
        yield 'name_normalized' => ['name_normalized'];
        yield 'short_name' => ['short_name'];
    }

    #[Test]
    #[DataProvider('provideEachSortableTextColumnDeclaresByteWiseCollationCases')]
    public function eachSortableTextColumnDeclaresByteWiseCollation(string $column): void
    {
        $collation = $this->connection->executeQuery(
            'SELECT collation_name FROM information_schema.columns '
            . 'WHERE table_name = :table AND column_name = :column',
            ['table' => 'bank', 'column' => $column],
        )->fetchOne();

        $this->assertSame(
            'C',
            $collation,
            \sprintf('Sortable text column "%s" must declare COLLATE "C" for byte-wise ordering.', $column),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideEachSortableTextColumnDeclaresByteWiseCollationCases(): iterable
    {
        yield 'name_normalized' => ['name_normalized'];
        yield 'short_name' => ['short_name'];
    }

    /**
     * @return ClassMetadata<Bank>
     */
    private function bankMetadata(): ClassMetadata
    {
        return $this->entityManager->getClassMetadata(Bank::class);
    }

    /**
     * The full set of index definitions on the `bank` table, read straight from `pg_indexes`. A
     * substring match on `indexdef` is the robust, dialect-honest way to assert physical index shape
     * (column order + UNIQUE-ness) without re-deriving Postgres' own DDL.
     *
     * @return list<string>
     */
    /**
     * @return list<string>
     */
    private function bankIndexDefinitionsFor(): array
    {
        /** @var list<string> $definitions */
        $definitions = $this->connection->executeQuery(
            'SELECT indexdef FROM pg_indexes WHERE tablename = :table',
            ['table' => 'bank'],
        )->fetchFirstColumn();

        return $definitions;
    }
}
