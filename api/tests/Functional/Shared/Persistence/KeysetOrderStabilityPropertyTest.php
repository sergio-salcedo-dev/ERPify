<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Persistence;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Shared\Search\Domain\Filter;
use Erpify\Shared\Search\Domain\FilterOperator;
use Erpify\Shared\Search\Domain\Filters;
use Erpify\Shared\Search\Domain\Page;
use Erpify\Shared\Search\Domain\SearchCriteria;
use Erpify\Shared\Search\Domain\SortDirection;
use Erpify\Shared\Domain\Uuid\Uuid;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\DoctrineSearchEngine;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\FieldMapping;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\FilterApplier;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\Keyset\Cursor;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\Keyset\CursorCodec;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\Keyset\CursorPositionExtractor;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\Keyset\FingerprintCanonicalizer;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\Keyset\KeysetPredicateBuilder;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\Keyset\WirePaginationPolicy;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\NormalizedTextFieldNormalizer;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\PaginatorConfig;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\RowUniquenessGuard;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\SearchFieldMap;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\SortFieldMap;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PropertyAccess\PropertyAccess;

/**
 * NORMATIVE property gate (AR13, AR4, AR23) for {@see DoctrineSearchEngine} against REAL Postgres —
 * no HTTP. It pins the keyset CORRECTNESS property, not any physical form: a `SortFieldMapIndexContractTest`
 * (shape) and a `KeysetSqlSnapshotTest` (snapshot) are subordinate to it.
 *
 * Adversarial dataset (AC #5): ~50 banks whose physical order is unrelated to their ids; ~80% share a
 * single `created_at`/`updated_at` SECOND (seeded from datetimes that differ only in microseconds — they
 * collapse under the schema's `TIMESTAMP(0)`); text in the safe `[a-z0-9]` alphabet. `limit` is kept below
 * the dominant tie group, so EVERY page boundary lands INSIDE a tie — exactly where multiplicity, NULL
 * omission, and a lost tie-break would break.
 *
 * The property is validated with the dataset + asserts ALONE — never by reading indexes, ClassMetadata, or
 * execution plans (AR4 correct-by-result): a deterministic full-scan oracle `ORDER BY (col, id)` is the
 * truth, and the engine's keyset walk must reproduce it exactly. Both sides read the SAME live column, so
 * the test is agnostic to collation and to whether the composite indexes of Story 1.2's migration exist —
 * it passes identically before and after that migration.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 */
#[CoversClass(DoctrineSearchEngine::class)]
final class KeysetOrderStabilityPropertyTest extends KernelTestCase
{
    /** Below the dominant tie group of ~40, so every boundary lands inside a tie. */
    private const int PAGE_LIMIT = 3;

    private const int DATASET_SIZE = 50;

    /** Share of rows packed into one created_at/updated_at second. */
    private const int TIE_GROUP_SIZE = 40;

    private EntityManagerInterface $entityManager;

    private DoctrineSearchEngine $engine;

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;

        // Built from concrete collaborators (not the container) so the gate is deterministic and the
        // test secret is fixed — the engine under test is pure wiring over the PR1 kernel.
        $this->engine = new DoctrineSearchEngine(
            new FilterApplier(),
            new CursorCodec('keyset-property-test-secret'),
            new FingerprintCanonicalizer(),
            new KeysetPredicateBuilder(),
            new CursorPositionExtractor(PropertyAccess::createPropertyAccessor()),
            new RowUniquenessGuard(),
        );
    }

    /**
     * Invariants 1–3: deterministic oracle, exact `after` partition (no duplicates, no gaps, length N),
     * intra-tie frontier (a boundary cursor resumes at the very next oracle row).
     */
    #[Test]
    #[DataProvider('sortKeyProvider')]
    public function theAfterWalkExactlyPartitionsTheDeterministicOracle(
        string $field,
        string $column,
        SortDirection $direction,
    ): void {
        $this->inRolledBackTransaction(function () use ($field, $column, $direction): void {
            $ids = $this->seedAdversarialDataset();

            $oracle = $this->oracleOrder($ids, $column, $direction);
            $walked = $this->walkAfter($ids, $field, $direction);

            // Exact partition: same multiset, same order, no duplicates, no gaps, length N.
            $this->assertSame(
                $oracle,
                $walked,
                \sprintf('after-walk diverged from the oracle for sort "%s %s".', $field, $direction->value),
            );
            $this->assertCount(\count($ids), $walked);
            $this->assertSame(\array_unique($walked), $walked, 'after-walk emitted a duplicate row.');
        });
    }

    /**
     * Invariant 4 — `before` symmetry: navigating back from page 2's boundary reproduces page 1 exactly
     * (the inverted oracle, re-reversed to caller order).
     */
    #[Test]
    #[DataProvider('sortKeyProvider')]
    public function theBeforeWalkIsTheExactInverseOfTheAfterWalk(
        string $field,
        string $column,
        SortDirection $direction,
    ): void {
        $this->inRolledBackTransaction(function () use ($field, $column, $direction): void {
            $ids = $this->seedAdversarialDataset();
            $oracle = $this->oracleOrder($ids, $column, $direction);

            $firstPage = $this->paginate($ids, $field, $direction, null, Cursor::DIRECTION_AFTER);
            $secondPage = $this->paginate($ids, $field, $direction, $firstPage->nextCursor, Cursor::DIRECTION_AFTER);

            $this->assertNotNull($secondPage->prevCursor);

            // before(page2.prevCursor) must return page 1, in the same caller-facing order.
            $back = $this->paginate($ids, $field, $direction, $secondPage->prevCursor, Cursor::DIRECTION_BEFORE);

            $expectedFirstPage = \array_slice($oracle, 0, self::PAGE_LIMIT);
            $this->assertSame($expectedFirstPage, $this->ids($firstPage));
            $this->assertSame($expectedFirstPage, $this->ids($back), 'before-walk did not invert the after-walk.');
        });
    }

    /**
     * @return iterable<string, array{string, string, SortDirection}>
     */
    public static function sortKeyProvider(): iterable
    {
        $columns = [
            'name' => 'nameNormalized',
            'shortName' => 'shortName',
            'createdAt' => 'createdAt',
            'updatedAt' => 'updatedAt',
        ];

        foreach ($columns as $field => $column) {
            foreach (SortDirection::cases() as $direction) {
                yield \sprintf('%s %s', $field, $direction->value) => [$field, $column, $direction];
            }
        }
    }

    /**
     * Invariant 5 — precision: microsecond-differing source datetimes collapse to one second group, so a
     * temporal sort with `limit` < the group still totally orders by the `id` tie-break (no row skipped or
     * repeated across the sub-second boundary). The full `after` partition over `created_at` proves it.
     */
    #[Test]
    public function subSecondTiesCollapseToOneGroupOrderedByTheTieBreak(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $ids = $this->seedAdversarialDataset();

            $walked = $this->walkAfter($ids, 'createdAt', SortDirection::ASC);

            $this->assertSame($this->oracleOrder($ids, 'createdAt', SortDirection::ASC), $walked);
            $this->assertCount(\count($ids), $walked);
        });
    }

    /**
     * @param list<string> $ids
     *
     * @return list<string> the seeded ids in the deterministic `(col, id)` total order
     */
    private function oracleOrder(array $ids, string $column, SortDirection $direction): array
    {
        /** @var list<Bank> $banks */
        $banks = $this->entityManager->createQueryBuilder()
            ->select('b')
            ->from(Bank::class, 'b')
            ->where('b.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->addOrderBy('b.' . $column, $direction->value)
            ->addOrderBy('b.id', SortDirection::ASC->value)
            ->getQuery()
            ->getResult()
        ;

        return \array_map(static fn (Bank $bank): string => (string) $bank->getId(), $banks);
    }

    /**
     * @param list<string> $ids
     *
     * @return list<string> ids collected by walking every `after` page to exhaustion
     */
    private function walkAfter(array $ids, string $field, SortDirection $direction): array
    {
        $collected = [];
        $cursor = null;
        $guard = 0;
        $maxIterations = self::DATASET_SIZE + 2;

        do {
            $page = $this->paginate($ids, $field, $direction, $cursor, Cursor::DIRECTION_AFTER);
            $collected = [...$collected, ...$this->ids($page)];
            $cursor = $page->nextCursor;
            ++$guard;
        } while ($page->hasNext && $guard < $maxIterations);

        return $collected;
    }

    /**
     * @param list<string> $ids
     *
     * @return Page<Bank>
     */
    private function paginate(
        array $ids,
        string $field,
        SortDirection $direction,
        ?string $cursor,
        string $routingDirection,
    ): Page {
        $criteria = new SearchCriteria(
            cursor: $cursor,
            limit: self::PAGE_LIMIT,
            filters: Filters::fromList([Filter::in('id', $ids)]),
            sort: $field,
            direction: $direction,
        );

        /** @var Page<Bank> $page */
        $page = $this->engine->paginate(
            $this->bankQueryBuilder(),
            $criteria,
            $this->searchFieldMap(),
            $this->sortFieldMap(),
            new PaginatorConfig(),
            WirePaginationPolicy::wire(),
            $routingDirection,
        );

        return $page;
    }

    /**
     * @param Page<Bank> $page
     *
     * @return list<string>
     */
    private function ids(Page $page): array
    {
        return \array_map(static fn (Bank $bank): string => (string) $bank->getId(), $page->items);
    }

    /**
     * Seeds ~50 banks: ~80% packed into a single created_at/updated_at second (datetimes differing only in
     * microseconds), the rest on distinct seconds; text `[a-z0-9]`; insertion order unrelated to id order.
     *
     * @return list<string> the seeded ids
     */
    private function seedAdversarialDataset(): array
    {
        $rows = $this->plannedRows();
        // Insert in an order unrelated to the id order so a false green from insertion↔order correlation
        // cannot hide: rotate the planned list by a fixed offset before persisting.
        $insertionOrder = [...\array_slice($rows, 17), ...\array_slice($rows, 0, 17)];

        $ids = [];

        foreach ($insertionOrder as $row) {
            $bank = Bank::create($row['id'], $row['name'], $row['shortName']);
            $bank->setCreatedAt($row['createdAt']);
            $bank->setUpdatedAt($row['updatedAt']);

            $this->entityManager->persist($bank);
            $ids[] = $row['id'];
        }

        $this->entityManager->flush();

        return $ids;
    }

    /**
     * @return list<array{
     *     id: string,
     *     name: string,
     *     shortName: string,
     *     createdAt: DateTimeImmutable,
     *     updatedAt: DateTimeImmutable,
     * }>
     */
    private function plannedRows(): array
    {
        $suffix = $this->uniqueSuffix();
        // UTC-pinned: Doctrine stores the datetime's own wall clock and the extractor normalizes to UTC,
        // so seeding in UTC keeps the stored value and the cursor boundary byte-identical (TIMESTAMP(0)).
        $tieSecond = new DateTimeImmutable('2026-01-15 10:00:00', new DateTimeZone('UTC'));
        $rows = [];

        for ($index = 0; $index < self::DATASET_SIZE; ++$index) {
            // 36 distinct alphanumerics across two positions give 50 collision-free, [a-z0-9] names.
            $token = $this->token($index);
            // ~80% share one second via microsecond-only differences (collapse under TIMESTAMP(0));
            // the rest get their own second so the non-degenerate tail is exercised too.
            $createdAt = $index < self::TIE_GROUP_SIZE
                ? $tieSecond->modify(\sprintf('+%d microseconds', ($index * 137) % 1_000_000))
                : $tieSecond->modify(\sprintf('+%d seconds', $index));

            $rows[] = [
                'id' => Uuid::generate(),
                'name' => \sprintf('bank %s %s', $token, $suffix),
                'shortName' => \strtoupper($token . \substr($suffix, 0, 3)),
                'createdAt' => $createdAt,
                'updatedAt' => $createdAt,
            ];
        }

        return $rows;
    }

    private function token(int $index): string
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';

        return $alphabet[\intdiv($index, 36) % 36] . $alphabet[$index % 36];
    }

    private function bankQueryBuilder(): QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()->select('b')->from(Bank::class, 'b');
    }

    private function searchFieldMap(): SearchFieldMap
    {
        return new SearchFieldMap([
            'name' => new FieldMapping('b.nameNormalized', new NormalizedTextFieldNormalizer()),
            'shortName' => new FieldMapping('b.shortName'),
            'id' => new FieldMapping(
                'b.id',
                operators: [FilterOperator::Eq, FilterOperator::In],
                requiresUuidValues: true,
            ),
        ]);
    }

    private function sortFieldMap(): SortFieldMap
    {
        return new SortFieldMap([
            'name' => 'b.nameNormalized',
            'shortName' => 'b.shortName',
            'createdAt' => 'b.createdAt',
            'updatedAt' => 'b.updatedAt',
        ]);
    }

    /**
     * @param Closure(): void $testBody
     */
    private function inRolledBackTransaction(Closure $testBody): void
    {
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $testBody();
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    private function uniqueSuffix(): string
    {
        return \strtolower(\substr(\str_replace('-', '', Uuid::generate()), -8));
    }
}
