<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Application\Http\Search;

use Erpify\Shared\Application\Http\Search\SearchQuery;
use Erpify\Shared\Domain\Search\PaginationMode;
use Generator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * @internal
 */
#[CoversClass(SearchQuery::class)]
final class SearchQueryTest extends TestCase
{
    private ValidatorInterface $validator;

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
            ids: ['11111111-1111-7000-8000-000000000001'],
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
        yield 'invalid uuid in ids' => [new SearchQuery(ids: ['not-a-uuid']), ['ids[0]']];
        yield 'mixed valid and invalid uuid in ids' => [
            new SearchQuery(ids: ['11111111-1111-7000-8000-000000000001', 'bad']),
            ['ids[1]'],
        ];
    }

    public function testToCriteriaProducesEquivalentDomainValueObject(): void
    {
        $searchQuery = new SearchQuery(
            cursor: 'abc',
            page: 3,
            limit: 25,
            paginationMode: PaginationMode::DETAILED,
            ids: ['11111111-1111-7000-8000-000000000001'],
        );

        $searchCriteria = $searchQuery->toCriteria();

        $this->assertSame('abc', $searchCriteria->cursor);
        $this->assertSame(3, $searchCriteria->page);
        $this->assertSame(25, $searchCriteria->limit);
        $this->assertSame(PaginationMode::DETAILED, $searchCriteria->paginationMode);
        $this->assertSame(['11111111-1111-7000-8000-000000000001'], $searchCriteria->ids);
    }

    public function testToCriteriaPropagatesMaxLimit(): void
    {
        $searchCriteria = (new SearchQuery())->toCriteria();

        $this->assertNull($searchCriteria->cursor);
        $this->assertSame(1, $searchCriteria->page);
        $this->assertSame(SearchQuery::MAX_LIMIT, $searchCriteria->limit);
        $this->assertSame(PaginationMode::LIGHT, $searchCriteria->paginationMode);
        $this->assertNull($searchCriteria->ids);
    }
}
