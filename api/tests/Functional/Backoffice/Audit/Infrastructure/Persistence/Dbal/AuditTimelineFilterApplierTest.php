<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Backoffice\Audit\Infrastructure\Persistence\Dbal;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Erpify\Backoffice\Audit\Infrastructure\Persistence\Dbal\AuditTimelineFilterApplier;
use Erpify\Shared\Search\Domain\Exception\InvalidSearchValue;
use Erpify\Shared\Search\Domain\Exception\UnknownSearchField;
use Erpify\Shared\Search\Domain\Exception\UnsupportedSearchOperator;
use Erpify\Shared\Search\Domain\Filter;
use Erpify\Shared\Search\Domain\FilterOperator;
use Erpify\Shared\Search\Domain\Filters;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\FieldMapping;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\SearchFieldMap;
use Erpify\Shared\Uuid\Domain\Uuid;
use InvalidArgumentException;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Compilation lock for the DBAL-native timeline filter applier over the entity-free `audit_log`
 * table: every client value travels as a bound parameter (never interpolated), UUID and timestamp
 * columns are CAST so the comparison keeps an index-backed operator, CONTAINS escapes LIKE
 * wildcards, range bounds are parsed strictly, and the mandatory allow-list rejects unmapped fields
 * and disallowed operators. Asserting the compiled SQL + bound parameters needs no execution, so the
 * test never touches a live connection.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 */
#[CoversClass(AuditTimelineFilterApplier::class)]
final class AuditTimelineFilterApplierTest extends KernelTestCase
{
    private const string VALID_UUID = '11111111-1111-7111-8111-111111111111';

    private Connection $connection;

    private AuditTimelineFilterApplier $filterApplier;

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $connection = self::getContainer()->get(Connection::class);
        $this->assertInstanceOf(Connection::class, $connection);

        $this->connection = $connection;
        $this->filterApplier = new AuditTimelineFilterApplier();
    }

    public function testEqOnATextFieldBindsAPlainEquals(): void
    {
        $queryBuilder = $this->apply(Filter::eq('level', 'security'));

        $this->assertStringContainsString('a.level = :f0', $queryBuilder->getSQL());
        $this->assertSame(['f0' => 'security'], $queryBuilder->getParameters());
    }

    public function testEqOnAUuidFieldCastsTheBoundParameter(): void
    {
        $queryBuilder = $this->apply(Filter::eq('actorId', self::VALID_UUID));

        $this->assertStringContainsString('a.actor_id = CAST(:f0 AS UUID)', $queryBuilder->getSQL());
        $this->assertSame(['f0' => self::VALID_UUID], $queryBuilder->getParameters());
    }

    public function testInBindsAListParameterTypedAsAStringArray(): void
    {
        $queryBuilder = $this->apply(Filter::in('level', ['security', 'activity']));

        $this->assertStringContainsString('a.level IN (:f0)', $queryBuilder->getSQL());
        $this->assertSame(['f0' => ['security', 'activity']], $queryBuilder->getParameters());
        $this->assertSame(['f0' => ArrayParameterType::STRING], $queryBuilder->getParameterTypes());
    }

    public function testContainsLowercasesBothSidesAndWrapsTheValueInWildcards(): void
    {
        $queryBuilder = $this->apply(Filter::contains('action', 'ROUTE'));

        $this->assertStringContainsString('LOWER(a.action) LIKE LOWER(:f0)', $queryBuilder->getSQL());
        $this->assertSame(['f0' => '%ROUTE%'], $queryBuilder->getParameters());
    }

    public function testContainsEscapesLikeWildcardsSoTheyMatchLiterally(): void
    {
        $queryBuilder = $this->apply(Filter::contains('action', '100%_a\b'));

        // Backslash first, then % and _ — a literal value can never act as a LIKE wildcard.
        $this->assertSame(['f0' => '%100\%\_a\\\b%'], $queryBuilder->getParameters());
    }

    #[DataProvider('provideRangeOperatorsCastToTimestamptzAndBindTheNormalizedBoundCases')]
    public function testRangeOperatorsCastToTimestamptzAndBindTheNormalizedBound(
        string $operator,
        string $sqlComparison,
    ): void {
        $queryBuilder = $this->apply($this->rangeFilter('occurredOn', $operator, '2026-03-01T11:57:00+00:00'));

        $this->assertStringContainsString(
            \sprintf('a.occurred_on %s CAST(:f0 AS TIMESTAMPTZ)', $sqlComparison),
            $queryBuilder->getSQL(),
        );
        $this->assertSame(['f0' => '2026-03-01 11:57:00.000000+00:00'], $queryBuilder->getParameters());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideRangeOperatorsCastToTimestamptzAndBindTheNormalizedBoundCases(): iterable
    {
        yield 'gt' => ['gt', '>'];
        yield 'gte' => ['gte', '>='];
        yield 'lt' => ['lt', '<'];
        yield 'lte' => ['lte', '<='];
    }

    #[DataProvider('provideRangeAcceptsEveryRfc3339BoundShapeAndNormalizesToUtcMicrosecondsCases')]
    public function testRangeAcceptsEveryRfc3339BoundShapeAndNormalizesToUtcMicroseconds(
        string $bound,
        string $expected,
    ): void {
        $queryBuilder = $this->apply($this->rangeFilter('occurredOn', 'gte', $bound));

        $this->assertSame(['f0' => $expected], $queryBuilder->getParameters());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideRangeAcceptsEveryRfc3339BoundShapeAndNormalizesToUtcMicrosecondsCases(): iterable
    {
        yield 'ATOM offset' => ['2026-03-01T11:57:00+00:00', '2026-03-01 11:57:00.000000+00:00'];
        yield 'ATOM Z' => ['2026-03-01T11:57:00Z', '2026-03-01 11:57:00.000000+00:00'];
        yield 'millisecond' => ['2026-03-01T11:57:00.123+00:00', '2026-03-01 11:57:00.123000+00:00'];
        yield 'microsecond' => ['2026-03-01T11:57:00.123456+00:00', '2026-03-01 11:57:00.123456+00:00'];
        yield 'non-utc offset folds to utc' => ['2026-03-01T13:57:00+02:00', '2026-03-01 11:57:00.000000+00:00'];
    }

    public function testRangeRejectsANonParseableBoundAsInvalidSearchValue(): void
    {
        try {
            $this->apply($this->rangeFilter('occurredOn', 'gte', 'tomorrow'));

            $this->fail('Expected InvalidSearchValue to be thrown.');
        } catch (InvalidSearchValue $invalidSearchValue) {
            $this->assertSame(['field' => 'occurredOn', 'position' => 0], $invalidSearchValue->context());
        }
    }

    public function testUnknownFieldIsRejected(): void
    {
        $this->expectException(UnknownSearchField::class);

        $this->apply(Filter::eq('bogus', 'x'));
    }

    public function testOperatorNotAllowedForTheFieldIsRejected(): void
    {
        // `level` is eq/in only — CONTAINS is not in its allow-list.
        $this->expectException(UnsupportedSearchOperator::class);

        $this->apply(Filter::contains('level', 'x'));
    }

    public function testRangeOnANonDatetimeFieldIsAProgrammerError(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->apply($this->rangeFilter('rawRange', 'gt', '2026-03-01T11:57:00+00:00'));
    }

    public function testMalformedUuidOnAUuidFieldIsRejectedWithItsPosition(): void
    {
        try {
            $this->apply(Filter::eq('actorId', 'not-a-uuid'));

            $this->fail('Expected InvalidSearchValue to be thrown.');
        } catch (InvalidSearchValue $invalidSearchValue) {
            $this->assertSame(['field' => 'actorId', 'position' => 0], $invalidSearchValue->context());
        }
    }

    public function testMalformedUuidInAListIsRejectedWithItsPosition(): void
    {
        try {
            $this->apply(Filter::in('actorId', [self::VALID_UUID, 'not-a-uuid']));

            $this->fail('Expected InvalidSearchValue to be thrown.');
        } catch (InvalidSearchValue $invalidSearchValue) {
            $this->assertSame(['field' => 'actorId', 'position' => 1], $invalidSearchValue->context());
        }
    }

    public function testEmptyInListIsRejectedAsProgrammerError(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->apply(Filter::in('level', []));
    }

    public function testEmptyFiltersIsASilentNoOp(): void
    {
        $queryBuilder = $this->queryBuilder();
        $sqlBefore = $queryBuilder->getSQL();

        $this->filterApplier->apply($queryBuilder, Filters::none(), $this->fieldMap());

        $this->assertSame($sqlBefore, $queryBuilder->getSQL());
        $this->assertSame([], $queryBuilder->getParameters());
    }

    public function testEachFilterGetsItsOwnIncrementingPlaceholderAndValuesAreNeverInterpolated(): void
    {
        $queryBuilder = $this->queryBuilder();

        $this->filterApplier->apply(
            $queryBuilder,
            Filters::fromList([Filter::eq('level', 'security'), Filter::contains('action', 'ROUTE')]),
            $this->fieldMap(),
        );

        $sql = $queryBuilder->getSQL();
        $this->assertStringContainsString(':f0', $sql);
        $this->assertStringContainsString(':f1', $sql);
        $this->assertStringNotContainsString('security', $sql);
        $this->assertStringNotContainsString('ROUTE', $sql);
        $this->assertCount(2, $queryBuilder->getParameters());
    }

    private function apply(Filter $filter): QueryBuilder
    {
        $queryBuilder = $this->queryBuilder();
        $this->filterApplier->apply($queryBuilder, Filters::fromList([$filter]), $this->fieldMap());

        return $queryBuilder;
    }

    private function rangeFilter(string $field, string $operator, string $value): Filter
    {
        return match ($operator) {
            'gt' => Filter::gt($field, $value),
            'gte' => Filter::gte($field, $value),
            'lt' => Filter::lt($field, $value),
            default => Filter::lte($field, $value),
        };
    }

    private function queryBuilder(): QueryBuilder
    {
        return $this->connection->createQueryBuilder()->select('a.id')->from('audit_log', 'a');
    }

    private function fieldMap(): SearchFieldMap
    {
        $textOperators = [FilterOperator::Eq, FilterOperator::In, FilterOperator::Contains];
        $rangeOperators = [FilterOperator::Gt, FilterOperator::Gte, FilterOperator::Lt, FilterOperator::Lte];

        return new SearchFieldMap([
            'level' => new FieldMapping('a.level', operators: [FilterOperator::Eq, FilterOperator::In]),
            'action' => new FieldMapping('a.action', operators: $textOperators),
            'actorId' => new FieldMapping(
                'a.actor_id',
                operators: [FilterOperator::Eq, FilterOperator::In],
                requiresUuidValues: true,
            ),
            'occurredOn' => new FieldMapping('a.occurred_on', operators: $rangeOperators, requiresDateTimeValues: true),
            // A range-capable column that is NOT declared datetime — exercises the applier's guard.
            'rawRange' => new FieldMapping('a.action', operators: [FilterOperator::Gt]),
        ]);
    }
}
