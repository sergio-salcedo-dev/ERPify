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
 * Verifies the structural preconditions for keyset pagination order stability.
 * Ensures sortable fields have appropriate indexes (UNIQUE or composite with id),
 * are NOT NULL, and use COLLATE "C" for deterministic byte-wise ordering.
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

        // Treat null as false (ORM 3 default). NULL-able keys break keyset boundaries.
        $this->assertNotTrue($nullable, \sprintf('Sort column for "%s" must be NOT NULL.', $entityField));
    }

    /**
     * Maps public sort names to entity fields for exhaustive contract verification.
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
     * Retrieves raw index definitions from pg_indexes for physical schema assertion.
     *
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
