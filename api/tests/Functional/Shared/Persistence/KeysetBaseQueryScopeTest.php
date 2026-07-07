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
use Erpify\Shared\Search\Domain\Exception\InvalidCursorCause;
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
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PropertyAccess\PropertyAccess;

/**
 * Direct gate (no HTTP) for the base-query discriminant against REAL Postgres.
 *
 * The keyset fingerprint used to derive from the root entity, filters, sort and limit only — never
 * the repository-authored base query. Two routes over the same aggregate root with divergent base
 * predicates (the unscoped account collection vs a bank's nested accounts) minted byte-identical
 * fingerprints, so a cursor crossed between them and paginated a foreign scope. This gate proves the
 * base query is now part of the cursor's identity: a cursor minted under one base query is rejected
 * (422 `invalid-cursor`, cause `fingerprint`) under a divergent one, while paging unchanged on its own.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversClass(DoctrineSearchEngine::class)]
final class KeysetBaseQueryScopeTest extends KernelTestCase
{
    private const int PAGE_LIMIT = 2;

    private const array SECOND_OFFSETS = [0, 1, 2, 3];

    private EntityManagerInterface $entityManager;

    private DoctrineSearchEngine $engine;

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;

        // One instance both mints and validates the token, exactly like a single deployed process.
        $this->engine = new DoctrineSearchEngine(
            new FilterApplier(),
            new CursorCodec('keyset-base-query-scope-test-secret'),
            new FingerprintCanonicalizer(),
            new KeysetPredicateBuilder(),
            new CursorPositionExtractor(PropertyAccess::createPropertyAccessor()),
            new RowUniquenessGuard(),
        );
    }

    #[Test]
    public function aCursorMintedUnderOneBaseQueryIsRejectedUnderADivergentOne(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $ids = $this->seedBanks();

            $page = $this->paginate($this->plainQueryBuilder(), $ids);
            $this->assertNotNull($page->nextCursor, 'The seeded dataset must expose a next cursor.');

            try {
                $this->paginate($this->scopedQueryBuilder(), $ids, $page->nextCursor);
                $this->fail('A cursor minted under a different base query must be rejected.');
            } catch (InvalidCursor $invalidCursor) {
                // Rejected specifically because the recomputed fingerprint no longer matches — the base
                // query segment diverges — not for any earlier (signature/version/dir) reason.
                $this->assertSame(InvalidCursorCause::Fingerprint, $invalidCursor->cause);
            }
        });
    }

    #[Test]
    public function aCursorRoundTripsOnItsOwnBaseQueryWithoutRegression(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $ids = $this->seedBanks();

            $first = $this->paginate($this->scopedQueryBuilder(), $ids);
            $this->assertNotNull($first->nextCursor);

            // Same base query on the follow-up: the cursor validates and the walk advances — the
            // discriminant does not regress same-scope pagination.
            $second = $this->paginate($this->scopedQueryBuilder(), $ids, $first->nextCursor);

            $this->assertNotSame($this->ids($first), $this->ids($second));
            $this->assertNotEmpty($this->ids($second));
        });
    }

    #[Test]
    public function aSyntheticCursorMintedUnderOneBaseQueryIsRejectedUnderADivergentOne(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $ids = $this->seedBanks();

            // The "go-to-date" synthetic token binds to the SAME base-query identity as a real cursor
            // (synthesizeCursor and paginate derive it in lockstep), so it must fail cross-scope too.
            $target = new DateTimeImmutable('2026-02-01 09:00:01', new DateTimeZone('UTC'));
            $token = $this->synthesize($this->plainQueryBuilder(), $ids, $target);

            try {
                $this->paginate($this->scopedQueryBuilder(), $ids, $token);
                $this->fail('A synthetic cursor minted under a different base query must be rejected.');
            } catch (InvalidCursor $invalidCursor) {
                $this->assertSame(InvalidCursorCause::Fingerprint, $invalidCursor->cause);
            }
        });
    }

    /**
     * @param list<string> $ids
     *
     * @return Page<Bank>
     */
    private function paginate(QueryBuilder $queryBuilder, array $ids, ?string $cursor = null): Page
    {
        /** @var Page<Bank> $page */
        $page = $this->engine->paginate(
            $queryBuilder,
            new SearchCriteria(
                cursor: $cursor,
                limit: self::PAGE_LIMIT,
                filters: Filters::fromList([Filter::in('id', $ids)]),
                sort: 'createdAt',
                direction: SortDirection::ASC,
            ),
            $this->searchFieldMap(),
            $this->sortFieldMap(),
            new PaginatorConfig(),
            WirePaginationPolicy::wire(),
            Cursor::DIRECTION_AFTER,
        );

        return $page;
    }

    /**
     * Mints a synthetic "go-to-date" cursor positioned at $target, bound to $queryBuilder's base identity.
     *
     * @param list<string> $ids
     */
    private function synthesize(QueryBuilder $queryBuilder, array $ids, DateTimeImmutable $target): string
    {
        return $this->engine->synthesizeCursor(
            $queryBuilder,
            new SearchCriteria(
                limit: self::PAGE_LIMIT,
                filters: Filters::fromList([Filter::in('id', $ids)]),
                sort: 'createdAt',
                direction: SortDirection::ASC,
            ),
            $this->searchFieldMap(),
            $this->sortFieldMap(),
            WirePaginationPolicy::wire(),
            $target,
        );
    }

    /** The unscoped base query — the collection route's shape (no base predicate). */
    private function plainQueryBuilder(): QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()->select('b')->from(Bank::class, 'b');
    }

    /**
     * A base query with a base predicate over the same rows — the nested route's shape. Same result
     * set as {@see plainQueryBuilder} (every seeded bank has a name), so the ONLY difference the
     * fingerprint can see is the base query's structure.
     */
    private function scopedQueryBuilder(): QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()
            ->select('b')
            ->from(Bank::class, 'b')
            ->where('b.name IS NOT NULL')
        ;
    }

    /**
     * @return list<string>
     */
    private function seedBanks(): array
    {
        $suffix = \strtolower(\substr(\str_replace('-', '', Uuid::generate()), -8));
        $base = new DateTimeImmutable('2026-02-01 09:00:00', new DateTimeZone('UTC'));

        $ids = [];

        foreach (self::SECOND_OFFSETS as $index => $offset) {
            $id = Uuid::generate();
            $bank = Bank::create(
                $id,
                \sprintf('scope bank %d %s', $index, $suffix),
                \strtoupper(\sprintf('SC%d%s', $index, \substr($suffix, 0, 3))),
            );
            $createdAt = $base->modify(\sprintf('+%d seconds', $offset));
            $bank->setCreatedAt($createdAt);
            $bank->setUpdatedAt($createdAt);

            $this->entityManager->persist($bank);
            $ids[] = $id;
        }

        $this->entityManager->flush();

        return $ids;
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
        return new SortFieldMap(['createdAt' => 'b.createdAt']);
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
