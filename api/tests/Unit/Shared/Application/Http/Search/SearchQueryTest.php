<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Application\Http\Search;

use Erpify\Shared\Application\Http\Search\FilterQuery;
use Erpify\Shared\Application\Http\Search\SearchQuery;
use Erpify\Shared\Domain\Search\Filter;
use Erpify\Shared\Domain\Search\FilterOperator;
use Erpify\Shared\Domain\Search\PaginationMode;
use Erpify\Shared\Domain\Search\SortDirection;
use Generator;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * @internal
 *
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 */
#[CoversClass(SearchQuery::class)]
final class SearchQueryTest extends TestCase
{
    private ValidatorInterface $validator;

    #[Override]
    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator()
        ;
    }

    public function testDefaultsAreValid(): void
    {
        $this->assertCount(0, $this->validator->validate(new SearchQuery()));
    }

    public function testFullyPopulatedHappyPathIsValid(): void
    {
        $searchQuery = new SearchQuery(
            cursor: 'abc',
            page: 2,
            limit: 50,
            paginationMode: PaginationMode::DETAILED,
            filters: [new FilterQuery('name', FilterOperator::Eq, 'BBVA')],
            sort: 'name',
            direction: SortDirection::DESC,
        );

        $this->assertCount(0, $this->validator->validate($searchQuery));
    }

    /**
     * @param list<string> $expectedPropertyPaths
     */
    #[DataProvider('provideValidatorRejectsInvalidInputCases')]
    public function testValidatorRejectsInvalidInput(SearchQuery $query, array $expectedPropertyPaths): void
    {
        $constraintViolationList = $this->validator->validate($query);

        $this->assertGreaterThan(0, $constraintViolationList->count(), 'expected at least one violation');

        $actualPaths = [];

        foreach ($constraintViolationList as $violation) {
            $actualPaths[] = $violation->getPropertyPath();
        }

        foreach ($expectedPropertyPaths as $expectedPropertyPath) {
            $this->assertContains($expectedPropertyPath, $actualPaths);
        }
    }

    /**
     * @return Generator<string, array{SearchQuery, list<string>}>
     */
    public static function provideValidatorRejectsInvalidInputCases(): iterable
    {
        yield 'page zero' => [new SearchQuery(page: 0), ['page']];
        yield 'page negative' => [new SearchQuery(page: -1), ['page']];
        yield 'page over cap' => [new SearchQuery(page: SearchQuery::MAX_PAGE + 1), ['page']];
        yield 'limit zero' => [new SearchQuery(limit: 0), ['limit']];
        yield 'limit over cap' => [new SearchQuery(limit: SearchQuery::MAX_LIMIT + 1), ['limit']];
        yield 'cursor too long' => [new SearchQuery(cursor: \str_repeat('a', 8193)), ['cursor']];
        yield 'sort too long' => [new SearchQuery(sort: \str_repeat('a', 65)), ['sort']];
    }

    public function testToCriteriaProducesEquivalentDomainValueObject(): void
    {
        $searchQuery = new SearchQuery(
            cursor: 'abc',
            page: 3,
            limit: 25,
            paginationMode: PaginationMode::DETAILED,
            sort: 'shortName',
            direction: SortDirection::DESC,
        );

        $searchCriteria = $searchQuery->toCriteria();

        $this->assertSame('abc', $searchCriteria->cursor);
        $this->assertSame(3, $searchCriteria->page);
        $this->assertSame(25, $searchCriteria->limit);
        $this->assertSame(PaginationMode::DETAILED, $searchCriteria->paginationMode);
        $this->assertSame('shortName', $searchCriteria->sort);
        $this->assertSame(SortDirection::DESC, $searchCriteria->direction);
    }

    public function testToCriteriaNormalizesAnEmptySortToNoOrdering(): void
    {
        // An empty `sort=` on the wire is "no sort", not an unknown field — it must not reach the
        // repository's allow-list lookup (which would 400); the criteria carries null instead.
        $searchCriteria = (new SearchQuery(sort: ''))->toCriteria();

        $this->assertNull($searchCriteria->sort);
    }

    public function testToCriteriaPropagatesMaxLimit(): void
    {
        $searchCriteria = (new SearchQuery())->toCriteria();

        $this->assertNull($searchCriteria->cursor);
        $this->assertSame(1, $searchCriteria->page);
        $this->assertSame(SearchQuery::MAX_LIMIT, $searchCriteria->limit);
        $this->assertSame(PaginationMode::LIGHT, $searchCriteria->paginationMode);
        $this->assertNull($searchCriteria->sort);
        $this->assertNotInstanceOf(SortDirection::class, $searchCriteria->direction);
    }

    public function testFiltersDefaultToEmptyDomainCollection(): void
    {
        $this->assertTrue((new SearchQuery())->toCriteria()->filters->isEmpty());
    }

    public function testValidFiltersPassValidation(): void
    {
        $searchQuery = new SearchQuery(filters: [
            new FilterQuery('name', FilterOperator::Contains, 'banc'),
            new FilterQuery('id', FilterOperator::In, ['11111111-1111-7000-8000-000000000001']),
        ]);

        $this->assertCount(0, $this->validator->validate($searchQuery));
    }

    public function testNestedFilterViolationsCascadeWithIndexedPaths(): void
    {
        $searchQuery = new SearchQuery(filters: [
            new FilterQuery('name', FilterOperator::In, 'not-a-list'),
        ]);

        $actualPaths = [];

        foreach ($this->validator->validate($searchQuery) as $constraintViolationList) {
            $actualPaths[] = $constraintViolationList->getPropertyPath();
        }

        $this->assertContains('filters[0].value', $actualPaths);
    }

    public function testFiltersOverCapAreRejected(): void
    {
        $searchQuery = new SearchQuery(filters: \array_fill(
            0,
            SearchQuery::MAX_FILTERS + 1,
            new FilterQuery('name', FilterOperator::Eq, 'x'),
        ));

        $actualPaths = [];

        foreach ($this->validator->validate($searchQuery) as $constraintViolationList) {
            $actualPaths[] = $constraintViolationList->getPropertyPath();
        }

        $this->assertContains('filters', $actualPaths);
    }

    public function testNonContiguousFilterIndexesAreRejected(): void
    {
        $searchQuery = new SearchQuery(filters: [1 => new FilterQuery('name', FilterOperator::Eq, 'x')]);

        $actualPaths = [];

        foreach ($this->validator->validate($searchQuery) as $constraintViolationList) {
            $actualPaths[] = $constraintViolationList->getPropertyPath();
        }

        $this->assertContains('filters', $actualPaths);
    }

    public function testToCriteriaTranslatesFiltersToDomain(): void
    {
        $searchQuery = new SearchQuery(filters: [
            new FilterQuery('name', FilterOperator::Contains, 'banc'),
            new FilterQuery('id', FilterOperator::In, ['a', 'b']),
        ]);

        $this->assertSame(
            [
                ['name', FilterOperator::Contains, 'banc'],
                ['id', FilterOperator::In, ['a', 'b']],
            ],
            \array_map(
                static fn (Filter $filter): array => [$filter->field, $filter->operator, $filter->value],
                $searchQuery->toCriteria()->filters->all(),
            ),
        );
    }
}
