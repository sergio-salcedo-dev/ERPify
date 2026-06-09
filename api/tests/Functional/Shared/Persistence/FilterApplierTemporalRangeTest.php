<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Persistence;

use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Shared\Domain\Search\Exception\InvalidSearchValue;
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
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration lock for the temporal range operators (gt/gte/lt/lte) of the filter applier
 * against real Postgres (never SQLite): boundary inclusivity, UTC normalization of offset
 * bounds, typed `datetime_immutable` binding (never a raw string against a timestamp column),
 * and the 400/programmer-error rejections.
 *
 * Each row-based test scopes its assertions with a unique name token so a CONTAINS filter
 * isolates exactly the rows it created from the shared dev database, while the temporal filter
 * discriminates by instant.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversClass(FilterApplier::class)]
final class FilterApplierTemporalRangeTest extends KernelTestCase
{
    /** Display-name prefix every persisted bank shares; the per-run token disambiguates rows. */
    private const string NAME_DISPLAY_PREFIX = 'Range ';

    /** Lowercased form the CONTAINS filter matches (the name normalizer lowercases the value). */
    private const string NAME_FILTER_PREFIX = 'range ';

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

    public function testGteRangeIncludesTheExactInstantAndLaterRows(): void
    {
        $this->inRolledBackTransaction(function (): void {
            [$early, $exact, $late, $base, $token] = $this->persistTemporalTriplet();

            $resultIds = $this->applyAndResultIds(Filters::fromList([
                Filter::contains('name', self::NAME_FILTER_PREFIX . $token),
                Filter::gte('createdAt', $base->format(DateTimeInterface::ATOM)),
            ]));

            $this->assertEqualsCanonicalizing([$exact->getId(), $late->getId()], $resultIds);
            $this->assertNotContains($early->getId(), $resultIds);

            // The triplet sets createdAt AND updatedAt to the same instant, so the identical
            // query over updatedAt must discriminate identically — proving updatedAt is wired to
            // its own column, not aliased to createdAt (a mis-mapped updatedAt would not).
            $updatedAtIds = $this->applyAndResultIds(Filters::fromList([
                Filter::contains('name', self::NAME_FILTER_PREFIX . $token),
                Filter::gte('updatedAt', $base->format(DateTimeInterface::ATOM)),
            ]));
            $this->assertEqualsCanonicalizing([$exact->getId(), $late->getId()], $updatedAtIds);
        });
    }

    public function testGtRangeExcludesTheExactInstant(): void
    {
        $this->inRolledBackTransaction(function (): void {
            [, , $late, $base, $token] = $this->persistTemporalTriplet();

            $resultIds = $this->applyAndResultIds(Filters::fromList([
                Filter::contains('name', self::NAME_FILTER_PREFIX . $token),
                Filter::gt('createdAt', $base->format(DateTimeInterface::ATOM)),
            ]));

            $this->assertSame([$late->getId()], $resultIds);

            // Same exclusivity over updatedAt — wired independently, not aliased to createdAt.
            $updatedAtIds = $this->applyAndResultIds(Filters::fromList([
                Filter::contains('name', self::NAME_FILTER_PREFIX . $token),
                Filter::gt('updatedAt', $base->format(DateTimeInterface::ATOM)),
            ]));
            $this->assertSame([$late->getId()], $updatedAtIds);
        });
    }

    public function testLteRangeIncludesTheExactInstantAndEarlierRows(): void
    {
        $this->inRolledBackTransaction(function (): void {
            [$early, $exact, , $base, $token] = $this->persistTemporalTriplet();

            $resultIds = $this->applyAndResultIds(Filters::fromList([
                Filter::contains('name', self::NAME_FILTER_PREFIX . $token),
                Filter::lte('createdAt', $base->format(DateTimeInterface::ATOM)),
            ]));

            $this->assertEqualsCanonicalizing([$early->getId(), $exact->getId()], $resultIds);
        });
    }

    public function testLtRangeExcludesTheExactInstant(): void
    {
        $this->inRolledBackTransaction(function (): void {
            [$early, , , $base, $token] = $this->persistTemporalTriplet();

            $resultIds = $this->applyAndResultIds(Filters::fromList([
                Filter::contains('name', self::NAME_FILTER_PREFIX . $token),
                Filter::lt('createdAt', $base->format(DateTimeInterface::ATOM)),
            ]));

            $this->assertSame([$early->getId()], $resultIds);
        });
    }

    public function testClosedRangeComposesGteAndLteWithAndAroundASingleInstant(): void
    {
        $this->inRolledBackTransaction(function (): void {
            [, $exact, , $base, $token] = $this->persistTemporalTriplet();

            $bound = $base->format(DateTimeInterface::ATOM);
            $resultIds = $this->applyAndResultIds(Filters::fromList([
                Filter::contains('name', self::NAME_FILTER_PREFIX . $token),
                Filter::gte('createdAt', $bound),
                Filter::lte('createdAt', $bound),
            ]));

            $this->assertSame([$exact->getId()], $resultIds);
        });
    }

    public function testRangeBoundOffsetIsNormalizedToUtc(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $token = \strtolower($this->uniqueSuffix());
            $bank = $this->createBankAt(
                self::NAME_DISPLAY_PREFIX . $token . ' Utc',
                'BR' . $this->uniqueSuffix(),
                new DateTimeImmutable('2026-06-01T12:00:00+00:00'),
            );

            // 14:00+02:00 is the same instant as 12:00 UTC: gte includes it (inclusive bound)...
            $included = $this->applyAndResultIds(Filters::fromList([
                Filter::contains('name', self::NAME_FILTER_PREFIX . $token),
                Filter::gte('createdAt', '2026-06-01T14:00:00+02:00'),
            ]));
            $this->assertSame([$bank->getId()], $included);

            // ...and gt excludes it. A bound left un-normalized would compare against the 14:00
            // wall-clock and wrongly keep the 12:00 row.
            $excluded = $this->applyAndResultIds(Filters::fromList([
                Filter::contains('name', self::NAME_FILTER_PREFIX . $token),
                Filter::gt('createdAt', '2026-06-01T14:00:00+02:00'),
            ]));
            $this->assertSame([], $excluded);
        });
    }

    public function testAcceptedRangeBoundsBindAsTypedUtcDateTimeImmutable(): void
    {
        $queryBuilder = $this->bankQueryBuilder();

        // The +00:00 offset form and the canonical JS Date.prototype.toISOString() form (millis
        // + Z) are both accepted, bound as typed datetime_immutable parameters — never a raw
        // string against a timestamp column. Two DISTINCT instants prove each format branch
        // parses to its own UTC value rather than coinciding on a single instant.
        $this->filterApplier->apply(
            $queryBuilder,
            Filters::fromList([
                Filter::gte('createdAt', '2026-01-01T00:00:00+00:00'),
                Filter::lte('createdAt', '2026-03-15T08:30:00.000Z'),
            ]),
            $this->bankFieldMap(),
        );

        $parameters = $queryBuilder->getParameters();
        $this->assertCount(2, $parameters);

        $boundInstants = [];

        foreach ($parameters as $parameter) {
            $value = $parameter->getValue();
            $this->assertInstanceOf(DateTimeImmutable::class, $value);
            $this->assertSame(Types::DATETIME_IMMUTABLE, $parameter->getType());
            $boundInstants[] = $value->format(DateTimeInterface::ATOM);
        }

        // Order across parameters is not guaranteed, so compare the set.
        $this->assertEqualsCanonicalizing(
            ['2026-01-01T00:00:00+00:00', '2026-03-15T08:30:00+00:00'],
            $boundInstants,
        );
    }

    #[DataProvider('provideInvalidRangeBoundIsRejectedAsInvalidSearchValueCases')]
    public function testInvalidRangeBoundIsRejectedAsInvalidSearchValue(string $value): void
    {
        try {
            $this->filterApplier->apply(
                $this->bankQueryBuilder(),
                Filters::fromList([Filter::gte('createdAt', $value)]),
                $this->bankFieldMap(),
            );

            $this->fail('Expected InvalidSearchValue to be thrown.');
        } catch (InvalidSearchValue $invalidSearchValue) {
            // Context carries the public field and the 0-based position — never the value.
            $this->assertSame(['field' => 'createdAt', 'position' => 0], $invalidSearchValue->context());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideInvalidRangeBoundIsRejectedAsInvalidSearchValueCases(): iterable
    {
        // "now"/date-only parse under the lenient constructor but must NOT under the strict
        // format set (a relative bound would be an unintended vector); the null byte makes
        // createFromFormat throw a ValueError that would otherwise escape as an engine 500.
        // The real-world offset span is asymmetric (UTC-12..UTC+14), so both +25:00 and the
        // non-existent -13:00 are rejected. All are client input → a 400, never a 5xx.
        yield 'malformed' => ['not-a-date'];
        yield 'lax relative form' => ['now'];
        yield 'date without time' => ['2026-01-01'];
        yield 'null byte' => ["2026-01-01T00:00:00+00:00\0evil"];
        yield 'out-of-range east utc offset' => ['2026-01-01T00:00:00+25:00'];
        yield 'out-of-range west utc offset' => ['2026-01-01T00:00:00-13:00'];
    }

    public function testRangeOperatorOnANonDateTimeFieldIsRejectedAsProgrammerError(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $fieldMap = new SearchFieldMap([
            'shortName' => new FieldMapping('b.shortName', operators: [FilterOperator::Gt]),
        ]);

        $this->filterApplier->apply(
            $this->bankQueryBuilder(),
            Filters::fromList([Filter::gt('shortName', 'x')]),
            $fieldMap,
        );
    }

    /**
     * Persists three banks sharing a unique name token at base-1s / base / base+1s.
     *
     * @return array{Bank, Bank, Bank, DateTimeImmutable, string} early, exact, late, base, token
     */
    private function persistTemporalTriplet(): array
    {
        $token = \strtolower($this->uniqueSuffix());
        $base = new DateTimeImmutable('2026-06-01T12:00:00+00:00');
        $displayBase = self::NAME_DISPLAY_PREFIX . $token;

        return [
            $this->createBankAt($displayBase . ' Early', 'BR' . $this->uniqueSuffix(), $base->modify('-1 second')),
            $this->createBankAt($displayBase . ' Exact', 'BR' . $this->uniqueSuffix(), $base),
            $this->createBankAt($displayBase . ' Late', 'BR' . $this->uniqueSuffix(), $base->modify('+1 second')),
            $base,
            $token,
        ];
    }

    private function createBankAt(string $name, string $shortName, DateTimeImmutable $instant): Bank
    {
        $bank = Bank::create(Uuid::generate(), $name, $shortName);
        $bank->setCreatedAt($instant);
        $bank->setUpdatedAt($instant);

        $this->entityManager->persist($bank);
        $this->entityManager->flush();

        return $bank;
    }

    /**
     * @return list<string|null>
     */
    private function applyAndResultIds(Filters $filters): array
    {
        $queryBuilder = $this->bankQueryBuilder();
        $this->filterApplier->apply($queryBuilder, $filters, $this->bankFieldMap());

        return $this->resultIds($queryBuilder);
    }

    private function bankFieldMap(): SearchFieldMap
    {
        $range = [FilterOperator::Gt, FilterOperator::Gte, FilterOperator::Lt, FilterOperator::Lte];

        return new SearchFieldMap([
            'name' => new FieldMapping('b.nameNormalized', new NormalizedTextFieldNormalizer()),
            'createdAt' => new FieldMapping('b.createdAt', operators: $range, requiresDateTimeValues: true),
            'updatedAt' => new FieldMapping('b.updatedAt', operators: $range, requiresDateTimeValues: true),
        ]);
    }

    /**
     * Always rolls the transaction back, so persisted rows never leak into the shared dev
     * database. A helper rather than a tearDown() override — rector and Psalm disagree on
     * #[Override] for hooks with parent calls; the helper avoids the override entirely.
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

    private function bankQueryBuilder(): QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()->select('b')->from(Bank::class, 'b');
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
