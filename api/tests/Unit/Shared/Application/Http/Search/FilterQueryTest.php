<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Application\Http\Search;

use Erpify\Shared\Application\Http\Search\FilterQuery;
use Erpify\Shared\Domain\Search\FilterOperator;
use Generator;
use LogicException;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * @internal
 */
#[CoversClass(FilterQuery::class)]
final class FilterQueryTest extends TestCase
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

    #[DataProvider('provideValidatorAcceptsValidInputCases')]
    public function testValidatorAcceptsValidInput(FilterQuery $filterQuery): void
    {
        $this->assertCount(0, $this->validator->validate($filterQuery));
    }

    /**
     * @return Generator<string, array{FilterQuery}>
     */
    public static function provideValidatorAcceptsValidInputCases(): iterable
    {
        yield 'eq with scalar value' => [new FilterQuery('name', FilterOperator::Eq, 'BBVA')];
        yield 'contains with scalar value' => [new FilterQuery('name', FilterOperator::Contains, 'banc')];
        yield 'in with list value' => [new FilterQuery('name', FilterOperator::In, ['BBVA', 'CaixaBank'])];
        yield 'in at values cap' => [
            new FilterQuery('name', FilterOperator::In, \array_fill(0, FilterQuery::MAX_IN_VALUES, 'v')),
        ];
        yield 'scalar at max length' => [new FilterQuery('name', FilterOperator::Eq, \str_repeat('a', 255))];
        yield 'gt with scalar value' => [new FilterQuery('createdAt', FilterOperator::Gt, '2026-01-01T00:00:00+00:00')];
        yield 'gte with scalar value' => [
            new FilterQuery('createdAt', FilterOperator::Gte, '2026-01-01T00:00:00+00:00'),
        ];
        yield 'lt with scalar value' => [new FilterQuery('createdAt', FilterOperator::Lt, '2026-01-01T00:00:00+00:00')];
        yield 'lte with scalar value' => [
            new FilterQuery('updatedAt', FilterOperator::Lte, '2026-01-01T00:00:00+00:00'),
        ];
    }

    /**
     * @param list<string> $expectedPropertyPaths
     */
    #[DataProvider('provideValidatorRejectsInvalidInputCases')]
    public function testValidatorRejectsInvalidInput(FilterQuery $filterQuery, array $expectedPropertyPaths): void
    {
        $constraintViolationList = $this->validator->validate($filterQuery);

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
     * @return Generator<string, array{FilterQuery, list<string>}>
     */
    public static function provideValidatorRejectsInvalidInputCases(): iterable
    {
        yield 'blank field' => [new FilterQuery('', FilterOperator::Eq, 'x'), ['field']];
        yield 'missing operator' => [new FilterQuery('name', null, 'x'), ['operator']];
        yield 'eq with list value' => [new FilterQuery('name', FilterOperator::Eq, ['x']), ['value']];
        yield 'contains with list value' => [new FilterQuery('name', FilterOperator::Contains, ['x']), ['value']];
        yield 'eq with blank value' => [new FilterQuery('name', FilterOperator::Eq, '   '), ['value']];
        // Unicode-whitespace-only values survive ASCII trim() but the field normalizer folds
        // them to blank downstream; they must be rejected at mapping, not 500 in the applier.
        yield 'eq with non-breaking-space value' => [
            new FilterQuery('name', FilterOperator::Eq, "\u{00A0}"), ['value'],
        ];
        yield 'contains with ideographic-space value' => [
            new FilterQuery('name', FilterOperator::Contains, "\u{3000}"),
            ['value'],
        ];
        yield 'contains with blank value' => [new FilterQuery('name', FilterOperator::Contains, ''), ['value']];
        yield 'scalar over max length' => [
            new FilterQuery('name', FilterOperator::Eq, \str_repeat('a', 256)),
            ['value'],
        ];
        yield 'in with scalar value' => [new FilterQuery('name', FilterOperator::In, 'x'), ['value']];
        yield 'in with empty list' => [new FilterQuery('name', FilterOperator::In, []), ['value']];
        yield 'in with string keys' => [new FilterQuery('name', FilterOperator::In, ['a' => 'x']), ['value']];
        yield 'in over values cap' => [
            new FilterQuery('name', FilterOperator::In, \array_fill(0, FilterQuery::MAX_IN_VALUES + 1, 'v')),
            ['value'],
        ];
        yield 'in with blank item' => [new FilterQuery('name', FilterOperator::In, ['ok', '   ']), ['value[1]']];
        yield 'in with non-breaking-space item' => [
            new FilterQuery('name', FilterOperator::In, ['ok', "\u{00A0}"]),
            ['value[1]'],
        ];
        yield 'in with non-string item' => [new FilterQuery('name', FilterOperator::In, [['nested']]), ['value[0]']];
        yield 'in with item over max length' => [
            new FilterQuery('name', FilterOperator::In, [\str_repeat('a', 256)]),
            ['value[0]'],
        ];
        yield 'gt with list value' => [
            new FilterQuery('createdAt', FilterOperator::Gt, ['2026-01-01T00:00:00+00:00']),
            ['value'],
        ];
        yield 'lte with list value' => [
            new FilterQuery('createdAt', FilterOperator::Lte, ['2026-01-01T00:00:00+00:00']),
            ['value'],
        ];
        yield 'gte with blank value' => [new FilterQuery('createdAt', FilterOperator::Gte, '   '), ['value']];
    }

    /**
     * @param string|list<string> $expectedValue
     */
    #[DataProvider('provideToFilterMapsOperatorsToNamedConstructorsCases')]
    public function testToFilterMapsOperatorsToNamedConstructors(
        FilterQuery $filterQuery,
        FilterOperator $expectedOperator,
        array|string $expectedValue,
    ): void {
        $filter = $filterQuery->toFilter();

        $this->assertSame($filterQuery->field, $filter->field);
        $this->assertSame($expectedOperator, $filter->operator);
        $this->assertSame($expectedValue, $filter->value);
    }

    /**
     * @return Generator<string, array{FilterQuery, FilterOperator, string|list<string>}>
     */
    public static function provideToFilterMapsOperatorsToNamedConstructorsCases(): iterable
    {
        yield 'eq' => [new FilterQuery('name', FilterOperator::Eq, 'BBVA'), FilterOperator::Eq, 'BBVA'];
        yield 'contains' => [
            new FilterQuery('name', FilterOperator::Contains, 'banc'),
            FilterOperator::Contains,
            'banc',
        ];
        yield 'in' => [
            new FilterQuery('id', FilterOperator::In, ['a', 'b']),
            FilterOperator::In,
            ['a', 'b'],
        ];

        $bound = '2026-01-01T00:00:00+00:00';
        yield 'gt' => [new FilterQuery('createdAt', FilterOperator::Gt, $bound), FilterOperator::Gt, $bound];
        yield 'gte' => [new FilterQuery('createdAt', FilterOperator::Gte, $bound), FilterOperator::Gte, $bound];
        yield 'lt' => [new FilterQuery('createdAt', FilterOperator::Lt, $bound), FilterOperator::Lt, $bound];
        yield 'lte' => [new FilterQuery('updatedAt', FilterOperator::Lte, $bound), FilterOperator::Lte, $bound];
    }

    public function testToFilterWithoutOperatorFailsLoudly(): void
    {
        $this->expectException(LogicException::class);

        (new FilterQuery('name', null, 'x'))->toFilter();
    }
}
