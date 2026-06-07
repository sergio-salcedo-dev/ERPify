<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Persistence;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Shared\Domain\Search\Exception\InvalidSearchValue;
use Erpify\Shared\Domain\Search\Exception\UnknownSearchField;
use Erpify\Shared\Domain\Search\Exception\UnsupportedSearchOperator;
use Erpify\Shared\Domain\Search\Filter;
use Erpify\Shared\Domain\Search\FilterOperator;
use Erpify\Shared\Domain\Search\Filters;
use Erpify\Shared\Domain\Uuid\Uuid;
use Erpify\Shared\Infrastructure\Persistence\Doctrine\Search\FieldMapping;
use Erpify\Shared\Infrastructure\Persistence\Doctrine\Search\FilterApplier;
use Erpify\Shared\Infrastructure\Persistence\Doctrine\Search\NormalizedTextFieldNormalizer;
use Erpify\Shared\Infrastructure\Persistence\Doctrine\Search\SearchFieldMap;
use InvalidArgumentException;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration lock for the filter applier against real Postgres (never SQLite): values are
 * always bound (never interpolated into DQL), the field's normalizer applies to every
 * operator, CONTAINS escapes LIKE wildcards, and the mandatory `SearchFieldMap` allow-list
 * rejects unmapped fields and disallowed operators.
 *
 * Tests that persist rows run inside {@see inRolledBackTransaction} — the suite has no DAMA
 * auto-rollback and shares the dev database connection, so rows carry per-test unique
 * suffixes and assertions never count whole tables.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 */
#[CoversClass(FilterApplier::class)]
#[CoversClass(SearchFieldMap::class)]
#[CoversClass(FieldMapping::class)]
#[CoversClass(NormalizedTextFieldNormalizer::class)]
final class FilterApplierTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    private FilterApplier $filterApplier;

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $this->entityManager = $entityManager;
        $this->filterApplier = new FilterApplier();
    }

    public function testEqWithNormalizerMatchesCaseAndDiacriticInsensitively(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $suffix = $this->uniqueSuffix();
            $bank = $this->createBank('Bánçó Ñandú ' . $suffix, 'BNE' . $suffix);

            $queryBuilder = $this->bankQueryBuilder();
            $this->filterApplier->apply(
                $queryBuilder,
                Filters::fromList([Filter::eq('name', '  BÁNÇÓ ñandú ' . $suffix . '  ')]),
                $this->bankFieldMap(),
            );

            $this->assertSame([$bank->getId()], $this->resultIds($queryBuilder));
        });
    }

    public function testInWithNormalizerAppliesNormalizationToEachItem(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $suffixOne = $this->uniqueSuffix();
            $suffixTwo = $this->uniqueSuffix();
            $suffixThree = $this->uniqueSuffix();
            $bankOne = $this->createBank('Bánçó Úno ' . $suffixOne, 'BNI' . $suffixOne);
            $bankTwo = $this->createBank('Banco Dos ' . $suffixTwo, 'BNI' . $suffixTwo);
            $this->createBank('Banco Tres ' . $suffixThree, 'BNI' . $suffixThree);

            $queryBuilder = $this->bankQueryBuilder();
            $this->filterApplier->apply(
                $queryBuilder,
                Filters::fromList([
                    Filter::in('name', ['BÁNÇÓ ÚNO ' . $suffixOne, 'banco dos ' . $suffixTwo]),
                ]),
                $this->bankFieldMap(),
            );

            $this->assertEqualsCanonicalizing(
                [$bankOne->getId(), $bankTwo->getId()],
                $this->resultIds($queryBuilder),
            );
        });
    }

    public function testContainsWithNormalizerFindsDiacriticTerm(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $suffix = $this->uniqueSuffix();
            $bank = $this->createBank('Bánçó Ñandú ' . $suffix, 'BNC' . $suffix);

            $queryBuilder = $this->bankQueryBuilder();
            $this->filterApplier->apply(
                $queryBuilder,
                Filters::fromList([Filter::contains('name', 'ÑANDÚ ' . $suffix)]),
                $this->bankFieldMap(),
            );

            $this->assertSame([$bank->getId()], $this->resultIds($queryBuilder));
        });
    }

    public function testContainsWithoutNormalizerFallsBackToCaseInsensitiveLike(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $suffix = $this->uniqueSuffix();
            $bank = $this->createBank('Banco Fallback ' . $suffix, 'BNF' . $suffix);

            $queryBuilder = $this->bankQueryBuilder();
            $this->filterApplier->apply(
                $queryBuilder,
                Filters::fromList([Filter::contains('shortName', 'bnf' . \strtolower($suffix))]),
                $this->bankFieldMap(),
            );

            $this->assertSame([$bank->getId()], $this->resultIds($queryBuilder));
        });
    }

    public function testContainsEscapesPercentWildcard(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $suffixLiteral = $this->uniqueSuffix();
            $suffixDecoy = $this->uniqueSuffix();
            $literal = $this->createBank('Banco 100% Legal ' . $suffixLiteral, 'BNP' . $suffixLiteral);
            $decoy = $this->createBank('Banco 100x Legal ' . $suffixDecoy, 'BNP' . $suffixDecoy);

            $queryBuilder = $this->bankQueryBuilder();
            $this->filterApplier->apply(
                $queryBuilder,
                Filters::fromList([Filter::contains('name', '100% Legal')]),
                $this->bankFieldMap(),
            );

            $resultIds = $this->resultIds($queryBuilder);
            $this->assertContains($literal->getId(), $resultIds);
            $this->assertNotContains($decoy->getId(), $resultIds);
        });
    }

    public function testContainsEscapesUnderscoreWildcard(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $suffixLiteral = $this->uniqueSuffix();
            $suffixDecoy = $this->uniqueSuffix();
            $literal = $this->createBank('Plan a_b ' . $suffixLiteral, 'BNL' . $suffixLiteral);
            $decoy = $this->createBank('Plan axb ' . $suffixDecoy, 'BNL' . $suffixDecoy);

            $queryBuilder = $this->bankQueryBuilder();
            $this->filterApplier->apply(
                $queryBuilder,
                Filters::fromList([Filter::contains('name', 'a_b')]),
                $this->bankFieldMap(),
            );

            $resultIds = $this->resultIds($queryBuilder);
            $this->assertContains($literal->getId(), $resultIds);
            $this->assertNotContains($decoy->getId(), $resultIds);
        });
    }

    public function testContainsEscapesBackslash(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $suffixLiteral = $this->uniqueSuffix();
            $suffixDecoy = $this->uniqueSuffix();
            $literal = $this->createBank('Ruta a\b ' . $suffixLiteral, 'BNB' . $suffixLiteral);
            // Unescaped '\b' in a LIKE pattern means literal 'b', so broken escaping would
            // match 'ab' while missing the row that actually contains 'a\b'.
            $decoy = $this->createBank('Ruta ab ' . $suffixDecoy, 'BNB' . $suffixDecoy);

            $queryBuilder = $this->bankQueryBuilder();
            $this->filterApplier->apply(
                $queryBuilder,
                Filters::fromList([Filter::contains('name', 'a\b')]),
                $this->bankFieldMap(),
            );

            $resultIds = $this->resultIds($queryBuilder);
            $this->assertContains($literal->getId(), $resultIds);
            $this->assertNotContains($decoy->getId(), $resultIds);
        });
    }

    public function testSameFieldFiltersComposeWithAnd(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $token = \strtolower($this->uniqueSuffix());
            $match = $this->createBank('Alfa ' . $token . ' Norte', 'BNA' . $this->uniqueSuffix());
            $this->createBank('Alfa ' . $token . ' Sur', 'BNA' . $this->uniqueSuffix());
            $this->createBank('Beta ' . $token . ' Norte', 'BNA' . $this->uniqueSuffix());

            $queryBuilder = $this->bankQueryBuilder();
            $this->filterApplier->apply(
                $queryBuilder,
                Filters::fromList([
                    Filter::contains('name', 'alfa ' . $token),
                    Filter::contains('name', 'norte'),
                ]),
                $this->bankFieldMap(),
            );

            $this->assertSame([$match->getId()], $this->resultIds($queryBuilder));
        });
    }

    public function testValuesAreBoundNeverInterpolated(): void
    {
        $queryBuilder = $this->bankQueryBuilder();
        $this->filterApplier->apply(
            $queryBuilder,
            Filters::fromList([
                Filter::eq('name', 'Bánçó Ñandú'),
                Filter::contains('name', 'ñandú'),
            ]),
            $this->bankFieldMap(),
        );

        $dql = $queryBuilder->getDQL();
        $this->assertStringNotContainsString('nandu', $dql);
        $this->assertStringNotContainsString('Ñandú', $dql);
        $this->assertCount(2, $queryBuilder->getParameters());

        foreach ($queryBuilder->getParameters() as $parameter) {
            $this->assertStringStartsWith('p', $parameter->getName());
            $this->assertStringContainsString(':' . $parameter->getName(), $dql);
        }
    }

    public function testEmptyFiltersIsASilentNoOp(): void
    {
        $queryBuilder = $this->bankQueryBuilder();
        $dqlBefore = $queryBuilder->getDQL();

        $this->filterApplier->apply($queryBuilder, Filters::none(), $this->bankFieldMap());

        $this->assertSame($dqlBefore, $queryBuilder->getDQL());
        $this->assertCount(0, $queryBuilder->getParameters());
    }

    public function testFieldOutsideTheAllowListIsRejected(): void
    {
        $this->expectException(UnknownSearchField::class);

        $this->filterApplier->apply(
            $this->bankQueryBuilder(),
            Filters::fromList([Filter::eq('secretColumn', 'x')]),
            $this->bankFieldMap(),
        );
    }

    public function testOperatorNotAllowedForTheFieldIsRejected(): void
    {
        $this->expectException(UnsupportedSearchOperator::class);

        $this->filterApplier->apply(
            $this->bankQueryBuilder(),
            Filters::fromList([Filter::contains('id', 'abc')]),
            $this->bankFieldMap(),
        );
    }

    public function testMalformedUuidOnUuidRequiringFieldIsRejected(): void
    {
        $this->expectException(InvalidSearchValue::class);

        $this->filterApplier->apply(
            $this->bankQueryBuilder(),
            Filters::fromList([Filter::eq('id', 'not-a-uuid')]),
            $this->bankFieldMap(),
        );
    }

    public function testMalformedUuidInListOnUuidRequiringFieldIsRejected(): void
    {
        $this->expectException(InvalidSearchValue::class);

        $this->filterApplier->apply(
            $this->bankQueryBuilder(),
            Filters::fromList([Filter::in('id', [Uuid::generate(), 'not-a-uuid'])]),
            $this->bankFieldMap(),
        );
    }

    public function testValidUuidOnUuidRequiringFieldIsBoundWithoutError(): void
    {
        $queryBuilder = $this->bankQueryBuilder();

        $this->filterApplier->apply(
            $queryBuilder,
            Filters::fromList([Filter::eq('id', Uuid::generate())]),
            $this->bankFieldMap(),
        );

        $this->assertCount(1, $queryBuilder->getParameters());
    }

    public function testEmptyInListIsRejectedAsProgrammerError(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->filterApplier->apply(
            $this->bankQueryBuilder(),
            Filters::fromList([Filter::in('name', [])]),
            $this->bankFieldMap(),
        );
    }

    public function testContainsValueNormalizingToEmptyIsRejectedAsProgrammerError(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->filterApplier->apply(
            $this->bankQueryBuilder(),
            Filters::fromList([Filter::contains('name', '   ')]),
            $this->bankFieldMap(),
        );
    }

    public function testEqValueNormalizingToEmptyIsRejectedAsProgrammerError(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->filterApplier->apply(
            $this->bankQueryBuilder(),
            Filters::fromList([Filter::eq('name', '   ')]),
            $this->bankFieldMap(),
        );
    }

    public function testInItemNormalizingToEmptyIsRejectedAsProgrammerError(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->filterApplier->apply(
            $this->bankQueryBuilder(),
            Filters::fromList([Filter::in('name', ['Banco Válido', '   '])]),
            $this->bankFieldMap(),
        );
    }

    /**
     * Runs the test body inside a transaction that is always rolled back, so persisted rows
     * never leak into the shared dev database. Deliberately not a tearDown() override —
     * rector and Psalm disagree on #[Override] for hooks with parent calls; a helper avoids
     * the override entirely.
     *
     * @param callable(): void $testBody
     */
    private function inRolledBackTransaction(callable $testBody): void
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

    private function bankFieldMap(): SearchFieldMap
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

    private function bankQueryBuilder(): QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()->select('b')->from(Bank::class, 'b');
    }

    private function createBank(string $name, string $shortName): Bank
    {
        $bank = Bank::create(Uuid::generate(), $name, $shortName);

        $this->entityManager->persist($bank);
        $this->entityManager->flush();

        return $bank;
    }

    /**
     * @return list<string|null>
     */
    private function resultIds(QueryBuilder $queryBuilder): array
    {
        /** @var list<Bank> $banks */
        $banks = $queryBuilder->getQuery()->getResult();

        return \array_map(static fn (Bank $bank): ?string => $bank->getId(), $banks);
    }

    private function uniqueSuffix(): string
    {
        // Last 8 hex chars: UUID v7 is time-ordered, so the FIRST chars are a timestamp prefix
        // shared by ids minted in the same run — only the tail is random enough per row.
        return \strtoupper(\substr(\str_replace('-', '', Uuid::generate()), -8));
    }
}
