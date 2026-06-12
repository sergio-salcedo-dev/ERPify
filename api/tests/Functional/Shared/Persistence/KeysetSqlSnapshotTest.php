<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Persistence;

use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware as LoggingMiddleware;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Shared\Domain\Search\Page;
use Erpify\Shared\Domain\Search\SearchCriteria;
use Erpify\Shared\Domain\Search\SortDirection;
use Erpify\Shared\Domain\Uuid\Uuid;
use Erpify\Shared\Infrastructure\Persistence\Doctrine\Search\DoctrineSearchEngine;
use Erpify\Shared\Infrastructure\Persistence\Doctrine\Search\FieldMapping;
use Erpify\Shared\Infrastructure\Persistence\Doctrine\Search\FilterApplier;
use Erpify\Shared\Infrastructure\Persistence\Doctrine\Search\Keyset\Cursor;
use Erpify\Shared\Infrastructure\Persistence\Doctrine\Search\Keyset\CursorCodec;
use Erpify\Shared\Infrastructure\Persistence\Doctrine\Search\Keyset\CursorPositionExtractor;
use Erpify\Shared\Infrastructure\Persistence\Doctrine\Search\Keyset\FingerprintCanonicalizer;
use Erpify\Shared\Infrastructure\Persistence\Doctrine\Search\Keyset\KeysetPredicateBuilder;
use Erpify\Shared\Infrastructure\Persistence\Doctrine\Search\Keyset\WirePaginationPolicy;
use Erpify\Shared\Infrastructure\Persistence\Doctrine\Search\NormalizedTextFieldNormalizer;
use Erpify\Shared\Infrastructure\Persistence\Doctrine\Search\PaginatorConfig;
use Erpify\Shared\Infrastructure\Persistence\Doctrine\Search\RowUniquenessGuard;
use Erpify\Shared\Infrastructure\Persistence\Doctrine\Search\SearchFieldMap;
use Erpify\Shared\Infrastructure\Persistence\Doctrine\Search\SortFieldMap;
use Erpify\Tests\Functional\Shared\Persistence\Fixtures\CapturingSqlLogger;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PropertyAccess\PropertyAccess;

/**
 * DERIVED, NON-NORMATIVE snapshot (AR4, AR20, AR22) of the keyset read-path SQL compiled by
 * {@see DoctrineSearchEngine}. It compares ONLY the SQL string + the bound parameters + the ordering —
 * never Doctrine objects. It is a regression DETECTOR, never a runtime compatibility contract: the
 * normative gate is {@see KeysetOrderStabilityPropertyTest} (a plan change that preserves the property
 * is not a regression — update this snapshot deliberately when the compiled SQL legitimately changes).
 *
 * The engine's queries are captured by running it through a parallel {@see EntityManager} wired to the
 * same database with a DBAL logging middleware; nothing is asserted by reaching into a QueryBuilder.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversClass(DoctrineSearchEngine::class)]
final class KeysetSqlSnapshotTest extends KernelTestCase
{
    private EntityManagerInterface $loggedEntityManager;

    private DoctrineSearchEngine $engine;

    private CapturingSqlLogger $logger;

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $realEntityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $realEntityManager);

        $this->logger = new CapturingSqlLogger();

        $dbalConfig = new DbalConfiguration();
        $dbalConfig->setMiddlewares([new LoggingMiddleware($this->logger)]);

        // why: getParams() is @internal but is the documented way to clone a live connection's config
        // onto a parallel logging connection in a test — there is no public alternative.
        $loggedConnection = DriverManager::getConnection($realEntityManager->getConnection()->getParams(), $dbalConfig);

        $this->loggedEntityManager = new EntityManager($loggedConnection, $realEntityManager->getConfiguration());

        $this->engine = new DoctrineSearchEngine(
            new FilterApplier(),
            new CursorCodec('keyset-snapshot-test-secret'),
            new FingerprintCanonicalizer(),
            new KeysetPredicateBuilder(),
            new CursorPositionExtractor(PropertyAccess::createPropertyAccessor()),
            new RowUniquenessGuard(),
        );
    }

    protected function tearDown(): void
    {
        // The parallel logging connection opened in setUp() is independent of the kernel's
        // managed connection, so kernel shutdown never reaches it — close it explicitly.
        $this->loggedEntityManager->getConnection()->close();

        parent::tearDown();
    }

    /**
     * Pins the compiled SQL of the cursor (second-page) read-path for a `name ASC` sort: the two-key
     * keyset predicate with explicit per-level grouping and the bare `id` tie-break qualified to the
     * root alias, the `(name, id)` ORDER BY, and the +1-trick LIMIT — every boundary value bound, none
     * interpolated.
     */
    #[Test]
    public function itCompilesTheExpectedKeysetReadPathSqlForACursorPage(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->seedTwoBanks();

            $firstPage = $this->paginate(null);
            $this->assertNotNull($firstPage->nextCursor);

            $this->logger->reset();
            $this->paginate($firstPage->nextCursor);

            $select = $this->lastBankSelect();

            // The keyset clause the ENGINE owns and compiles — pinned exactly. The full SELECT projection
            // is the entity's (Doctrine's) concern, so it is deliberately NOT pinned here (a new Bank
            // column must not break this snapshot). Doctrine emits positional `?` placeholders, so every
            // boundary value travels as a bound parameter — none is interpolated into the SQL text.
            $this->assertStringContainsString(
                'FROM bank b0_ '
                . 'WHERE ((b0_.name_normalized > ?) OR (b0_.name_normalized = ? AND (b0_.id > ?))) '
                . 'ORDER BY b0_.name_normalized ASC, b0_.id ASC LIMIT 4',
                $select['sql'],
            );

            // The engine never adds DISTINCT (Row Uniqueness Contract is a guard, not a DISTINCT crutch).
            $this->assertStringNotContainsStringIgnoringCase('DISTINCT', $select['sql']);

            // Binds: 3 boundary values (name, name, id) for the nested predicate — all bound, all scalar.
            $this->assertCount(3, $select['params']);

            foreach ($select['params'] as $value) {
                $this->assertIsString($value);
            }
        });
    }

    /**
     * @return array{sql: string, params: array<int|string, mixed>}
     */
    private function lastBankSelect(): array
    {
        $selects = \array_values(\array_filter(
            $this->logger->records,
            static fn (array $record): bool => \str_contains((string) $record['sql'], 'FROM bank b0_')
                && \str_contains((string) $record['sql'], 'ORDER BY'),
        ));

        if ([] === $selects) {
            $this->fail('no keyset SELECT against bank was captured.');
        }

        return \array_last($selects);
    }

    /**
     * @return Page<Bank>
     */
    private function paginate(?string $cursor): Page
    {
        $criteria = new SearchCriteria(
            cursor: $cursor,
            limit: 3,
            sort: 'name',
            direction: SortDirection::ASC,
        );

        /** @var Page<Bank> $page */
        $page = $this->engine->paginate(
            $this->bankQueryBuilder(),
            $criteria,
            $this->searchFieldMap(),
            $this->sortFieldMap(),
            new PaginatorConfig(),
            WirePaginationPolicy::wire(),
            Cursor::DIRECTION_AFTER,
        );

        return $page;
    }

    private function seedTwoBanks(): void
    {
        foreach (['alpha', 'bravo'] as $index => $name) {
            $suffix = \substr(\str_replace('-', '', Uuid::generate()), -6);
            $bank = Bank::create(Uuid::generate(), \sprintf('%s %s', $name, $suffix), \strtoupper($index . $suffix));
            $this->loggedEntityManager->persist($bank);
        }

        $this->loggedEntityManager->flush();
    }

    private function bankQueryBuilder(): QueryBuilder
    {
        return $this->loggedEntityManager->createQueryBuilder()->select('b')->from(Bank::class, 'b');
    }

    private function searchFieldMap(): SearchFieldMap
    {
        return new SearchFieldMap([
            'name' => new FieldMapping('b.nameNormalized', new NormalizedTextFieldNormalizer()),
        ]);
    }

    private function sortFieldMap(): SortFieldMap
    {
        return new SortFieldMap(['name' => 'b.nameNormalized']);
    }

    /**
     * @param callable(): void $testBody
     */
    private function inRolledBackTransaction(callable $testBody): void
    {
        $connection = $this->loggedEntityManager->getConnection();
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
