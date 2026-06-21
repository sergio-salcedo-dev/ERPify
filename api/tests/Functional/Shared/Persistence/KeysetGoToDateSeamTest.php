<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Persistence;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Shared\Search\Domain\Exception\InvalidCursor;
use Erpify\Shared\Search\Domain\Filter;
use Erpify\Shared\Search\Domain\FilterOperator;
use Erpify\Shared\Search\Domain\Filters;
use Erpify\Shared\Search\Domain\Page;
use Erpify\Shared\Search\Domain\SearchCriteria;
use Erpify\Shared\Search\Domain\SortDirection;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\DoctrineSearchEngine;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\FieldMapping;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\FilterApplier;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\Keyset\Cursor;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\Keyset\CursorCodec;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\Keyset\CursorPositionExtractor;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\Keyset\FingerprintCanonicalizer;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\Keyset\KeysetPredicateBuilder;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\Keyset\WirePaginationPolicy;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\PaginatorConfig;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\RowUniquenessGuard;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\SearchFieldMap;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\SortFieldMap;
use Erpify\Shared\Uuid\Domain\Uuid;
use LogicException;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PropertyAccess\PropertyAccess;

/**
 * Direct gate (no HTTP) for the "go-to-date" seam against REAL Postgres: a cursor SYNTHESIZED from
 * a sort-key value must behave exactly like one minted off a boundary row — same signing, same
 * fingerprint binding, same navigation pipeline — and land INCLUSIVELY at the target value with the
 * conservative `hasPrev: true` affordance.
 *
 * Dataset: 8 banks on known `created_at` seconds with a 3-row tie group, isolated from any ambient
 * rows by an `id IN (…)` filter, so every assert is an exact list comparison against a full-scan
 * oracle `ORDER BY (created_at, id)`.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 * @SuppressWarnings("PHPMD.ExcessiveClassLength")
 */
#[CoversClass(DoctrineSearchEngine::class)]
final class KeysetGoToDateSeamTest extends KernelTestCase
{
    /** Above the dataset size, so a landing page exposes the full remainder in one fetch. */
    private const int PAGE_LIMIT = 25;

    /** Offsets in seconds from the base second; three rows tie on +5. */
    private const array SECOND_OFFSETS = [0, 1, 2, 3, 5, 5, 5, 8];

    private const int TIE_OFFSET = 5;

    private EntityManagerInterface $entityManager;

    private DoctrineSearchEngine $engine;

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;

        // Concrete collaborators with a fixed secret: the same engine must both synthesize the
        // token and validate it on the follow-up paginate, exactly like a single deployed instance.
        $this->engine = new DoctrineSearchEngine(
            new FilterApplier(),
            new CursorCodec('keyset-goto-date-seam-test-secret'),
            new FingerprintCanonicalizer(),
            new KeysetPredicateBuilder(),
            new CursorPositionExtractor(PropertyAccess::createPropertyAccessor()),
            new RowUniquenessGuard(),
        );
    }

    /**
     * ASC landing is inclusive: the page starts at the FIRST row whose key equals the target,
     * the whole tie group is present, and the remainder matches the oracle exactly. A target
     * carrying microseconds collapses to the same column-precision position.
     */
    #[Test]
    public function anAscendingLandingIsInclusiveOfTheTargetValueAndItsTies(): void
    {
        $this->inRolledBackTransaction(function (): void {
            [$ids, $base] = $this->seedDataset();
            $target = $base->modify(\sprintf('+%d seconds', self::TIE_OFFSET));

            $token = $this->synthesize($ids, 'createdAt', SortDirection::ASC, $target);
            $page = $this->paginate($ids, 'createdAt', SortDirection::ASC, $token);

            $oracle = $this->oracleOrder($ids, SortDirection::ASC);
            // Offsets [0,1,2,3] precede the target; the landing is the tie group (+5 ×3) onward.
            $this->assertSame(\array_slice($oracle, 4), $this->ids($page));

            $microTarget = $target->modify('+999999 microseconds');
            $microToken = $this->synthesize($ids, 'createdAt', SortDirection::ASC, $microTarget);
            $microPage = $this->paginate($ids, 'createdAt', SortDirection::ASC, $microToken);
            $this->assertSame($this->ids($page), $this->ids($microPage));
        });
    }

    /** DESC landing mirrors FR-style "key <= target": ties first, then every earlier second. */
    #[Test]
    public function aDescendingLandingStartsAtTheTargetValueGoingBackwards(): void
    {
        $this->inRolledBackTransaction(function (): void {
            [$ids, $base] = $this->seedDataset();
            $target = $base->modify(\sprintf('+%d seconds', self::TIE_OFFSET));

            $token = $this->synthesize($ids, 'createdAt', SortDirection::DESC, $target);
            $page = $this->paginate($ids, 'createdAt', SortDirection::DESC, $token);

            $oracle = $this->oracleOrder($ids, SortDirection::DESC);
            // Only the +8 row sorts before the target in DESC order.
            $this->assertSame(\array_slice($oracle, 1), $this->ids($page));
        });
    }

    /**
     * The conservative affordance: a target before the whole dataset lands on its literal first
     * page, yet `hasPrev` is true — the flag affirms link availability, never row existence (K10).
     */
    #[Test]
    public function landingOnTheVeryFirstRowStillReportsHasPrevTrue(): void
    {
        $this->inRolledBackTransaction(function (): void {
            [$ids, $base] = $this->seedDataset();

            $token = $this->synthesize($ids, 'createdAt', SortDirection::ASC, $base->modify('-1 hour'));
            $page = $this->paginate($ids, 'createdAt', SortDirection::ASC, $token);

            $this->assertSame($this->oracleOrder($ids, SortDirection::ASC), $this->ids($page));
            $this->assertTrue($page->hasPrev);
            $this->assertFalse($page->hasNext);
        });
    }

    /** A target beyond the dataset is gap navigation: empty page with the recovery affordance (W10). */
    #[Test]
    public function aTargetBeyondTheDatasetLandsOnAnEmptyPageWithARecoveryCursor(): void
    {
        $this->inRolledBackTransaction(function (): void {
            [$ids, $base] = $this->seedDataset();

            $token = $this->synthesize($ids, 'createdAt', SortDirection::ASC, $base->modify('+1 hour'));
            $page = $this->paginate($ids, 'createdAt', SortDirection::ASC, $token);

            $this->assertSame([], $page->items);
            $this->assertFalse($page->hasNext);
            $this->assertTrue($page->hasPrev);
            $this->assertNotNull($page->prevCursor);
            $this->assertNull($page->nextCursor);
        });
    }

    /**
     * The synthetic landing joins the normal navigation pipeline: with a small limit, following
     * `nextCursor` from the landing page partitions the remainder exactly — no duplicate, no gap.
     */
    #[Test]
    public function walkingForwardFromASyntheticLandingPartitionsTheRemainder(): void
    {
        $this->inRolledBackTransaction(function (): void {
            [$ids, $base] = $this->seedDataset();
            $target = $base->modify(\sprintf('+%d seconds', self::TIE_OFFSET));
            $limit = 3;

            $token = $this->synthesize($ids, 'createdAt', SortDirection::ASC, $target, $limit);
            $landing = $this->paginate($ids, 'createdAt', SortDirection::ASC, $token, $limit);

            $oracle = $this->oracleOrder($ids, SortDirection::ASC);
            $this->assertSame(\array_slice($oracle, 4, $limit), $this->ids($landing));
            $this->assertTrue($landing->hasNext);
            $this->assertNotNull($landing->nextCursor);

            $next = $this->paginate($ids, 'createdAt', SortDirection::ASC, $landing->nextCursor, $limit);
            $this->assertSame(\array_slice($oracle, 4 + $limit), $this->ids($next));
            $this->assertFalse($next->hasNext);
        });
    }

    /** The empty landing's recovery cursor is usable: a `before` walk on it returns to the data. */
    #[Test]
    public function theRecoveryCursorOfAnEmptyLandingWalksBackToTheData(): void
    {
        $this->inRolledBackTransaction(function (): void {
            [$ids, $base] = $this->seedDataset();

            $token = $this->synthesize($ids, 'createdAt', SortDirection::ASC, $base->modify('+1 hour'));
            $empty = $this->paginate($ids, 'createdAt', SortDirection::ASC, $token);

            $this->assertNotNull($empty->prevCursor);
            $back = $this->paginate(
                $ids,
                'createdAt',
                SortDirection::ASC,
                $empty->prevCursor,
                routingDirection: Cursor::DIRECTION_BEFORE,
            );

            $this->assertSame($this->oracleOrder($ids, SortDirection::ASC), $this->ids($back));
            $this->assertTrue($back->hasNext);
        });
    }

    /**
     * The token binds to the exact query it was synthesized for: a follow-up that differs in any
     * fingerprint axis (sort, limit, filters, direction) is a 422-family reject.
     */
    #[Test]
    #[DataProvider('provideTheTokenIsRejectedWhenTheFollowUpQueryDiffersCases')]
    public function theTokenIsRejectedWhenTheFollowUpQueryDiffers(
        string $field,
        SortDirection $direction,
        int $limit,
        int $droppedIdFilterValues,
    ): void {
        $followUp = function () use ($field, $direction, $limit, $droppedIdFilterValues): void {
            [$ids, $base] = $this->seedDataset();

            $token = $this->synthesize($ids, 'createdAt', SortDirection::ASC, $base);

            $this->expectException(InvalidCursor::class);
            $this->paginate(\array_slice($ids, $droppedIdFilterValues), $field, $direction, $token, $limit);
        };

        $this->inRolledBackTransaction($followUp);
    }

    /**
     * @return iterable<string, array{string, SortDirection, int, int}>
     */
    public static function provideTheTokenIsRejectedWhenTheFollowUpQueryDiffersCases(): iterable
    {
        yield 'different sort' => ['name', SortDirection::ASC, self::PAGE_LIMIT, 0];

        yield 'different limit' => ['createdAt', SortDirection::ASC, self::PAGE_LIMIT - 1, 0];

        yield 'different filters' => ['createdAt', SortDirection::ASC, self::PAGE_LIMIT, 1];

        yield 'different direction' => ['createdAt', SortDirection::DESC, self::PAGE_LIMIT, 0];
    }

    /**
     * Misuse fails loud at synthesis, never as a signed token: no primary sort to position by,
     * a sort that resolves to the `id` tie-break (the target would replace the inclusive floor),
     * and a null target (its predicate would match nothing).
     */
    #[Test]
    #[DataProvider('provideSynthesizingAMisusedPositionFailsLoudCases')]
    public function synthesizingAMisusedPositionFailsLoud(?string $sort, mixed $target): void
    {
        $this->expectException(LogicException::class);

        $this->engine->synthesizeCursor(
            $this->bankQueryBuilder(),
            new SearchCriteria(limit: self::PAGE_LIMIT, sort: $sort, direction: SortDirection::ASC),
            $this->searchFieldMap(),
            new SortFieldMap(['createdAt' => 'b.createdAt', 'id' => 'b.id']),
            WirePaginationPolicy::wire(),
            $target,
        );
    }

    /**
     * @return iterable<string, array{?string, mixed}>
     */
    public static function provideSynthesizingAMisusedPositionFailsLoudCases(): iterable
    {
        $target = new DateTimeImmutable('2026-01-15 10:00:00', new DateTimeZone('UTC'));

        yield 'no sort' => [null, $target];

        yield 'empty sort' => ['', $target];

        yield 'sort over the id tie-break' => ['id', $target];

        yield 'null target' => ['createdAt', null];
    }

    /**
     * @param list<string> $ids
     */
    private function synthesize(
        array $ids,
        string $field,
        SortDirection $direction,
        mixed $target,
        int $limit = self::PAGE_LIMIT,
    ): string {
        return $this->engine->synthesizeCursor(
            $this->bankQueryBuilder(),
            $this->criteria($ids, $field, $direction, null, $limit),
            $this->searchFieldMap(),
            $this->sortFieldMap(),
            WirePaginationPolicy::wire(),
            $target,
        );
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
        string $cursor,
        int $limit = self::PAGE_LIMIT,
        string $routingDirection = Cursor::DIRECTION_AFTER,
    ): Page {
        /** @var Page<Bank> $page */
        $page = $this->engine->paginate(
            $this->bankQueryBuilder(),
            $this->criteria($ids, $field, $direction, $cursor, $limit),
            $this->searchFieldMap(),
            $this->sortFieldMap(),
            new PaginatorConfig(),
            WirePaginationPolicy::wire(),
            $routingDirection,
        );

        return $page;
    }

    /**
     * @param list<string> $ids
     */
    private function criteria(
        array $ids,
        string $field,
        SortDirection $direction,
        ?string $cursor,
        int $limit,
    ): SearchCriteria {
        return new SearchCriteria(
            cursor: $cursor,
            limit: $limit,
            filters: Filters::fromList([Filter::in('id', $ids)]),
            sort: $field,
            direction: $direction,
        );
    }

    /**
     * @param list<string> $ids
     *
     * @return list<string> the seeded ids in the deterministic `(created_at, id)` total order
     */
    private function oracleOrder(array $ids, SortDirection $direction): array
    {
        /** @var list<Bank> $banks */
        $banks = $this->entityManager->createQueryBuilder()
            ->select('b')
            ->from(Bank::class, 'b')
            ->where('b.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->addOrderBy('b.createdAt', $direction->value)
            ->addOrderBy('b.id', SortDirection::ASC->value)
            ->getQuery()
            ->getResult()
        ;

        return \array_map(static fn (Bank $bank): string => (string) $bank->getId(), $banks);
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
     * Seeds banks on the {@see self::SECOND_OFFSETS} seconds, inserted in an order unrelated to the
     * id order so a false green from insertion↔order correlation cannot hide.
     *
     * @return array{list<string>, DateTimeImmutable} the seeded ids and the UTC base second
     */
    private function seedDataset(): array
    {
        $suffix = \strtolower(\substr(\str_replace('-', '', Uuid::generate()), -8));
        // UTC-pinned so the stored value and the cursor boundary stay byte-identical (TIMESTAMP(0)).
        $base = new DateTimeImmutable('2026-01-15 10:00:00', new DateTimeZone('UTC'));

        $rows = [];

        foreach (self::SECOND_OFFSETS as $index => $offset) {
            $rows[] = [
                'id' => Uuid::generate(),
                'name' => \sprintf('goto bank %d %s', $index, $suffix),
                'shortName' => \strtoupper(\sprintf('GD%d%s', $index, \substr($suffix, 0, 3))),
                'createdAt' => $base->modify(\sprintf('+%d seconds', $offset)),
            ];
        }

        $ids = [];

        foreach ([...\array_slice($rows, 3), ...\array_slice($rows, 0, 3)] as $row) {
            $bank = Bank::create($row['id'], $row['name'], $row['shortName']);
            $bank->setCreatedAt($row['createdAt']);
            $bank->setUpdatedAt($row['createdAt']);

            $this->entityManager->persist($bank);
            $ids[] = $row['id'];
        }

        $this->entityManager->flush();

        return [$ids, $base];
    }

    private function bankQueryBuilder(): QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()->select('b')->from(Bank::class, 'b');
    }

    private function searchFieldMap(): SearchFieldMap
    {
        return new SearchFieldMap([
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
            'createdAt' => 'b.createdAt',
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
}
