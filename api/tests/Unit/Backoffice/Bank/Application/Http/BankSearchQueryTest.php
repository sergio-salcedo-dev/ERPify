<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\Bank\Application\Http;

use Erpify\Backoffice\Bank\Application\Http\BankSearchQuery;
use Erpify\Shared\Application\Http\Search\FilterQuery;
use Erpify\Shared\Domain\Search\Filter;
use Erpify\Shared\Domain\Search\FilterOperator;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * @internal
 */
#[CoversClass(BankSearchQuery::class)]
final class BankSearchQueryTest extends TestCase
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

    public function testToCriteriaTransportsFiltersAlongsideLegacyParams(): void
    {
        $bankSearchQuery = new BankSearchQuery(
            names: ['BBVA'],
            filters: [new FilterQuery('name', FilterOperator::Eq, 'BBVA')],
        );

        $bankSearchCriteria = $bankSearchQuery->toCriteria();

        $this->assertSame(['BBVA'], $bankSearchCriteria->names);
        $this->assertSame(
            [['name', FilterOperator::Eq, 'BBVA']],
            \array_map(
                static fn (Filter $filter): array => [$filter->field, $filter->operator, $filter->value],
                $bankSearchCriteria->filters->all(),
            ),
        );
    }

    public function testToCriteriaWithoutFiltersYieldsEmptyDomainFilters(): void
    {
        $this->assertTrue((new BankSearchQuery())->toCriteria()->filters->isEmpty());
    }

    public function testInheritedFilterConstraintsApplyToNestedFilters(): void
    {
        $bankSearchQuery = new BankSearchQuery(filters: [new FilterQuery('', null, '')]);

        $actualPaths = [];

        foreach ($this->validator->validate($bankSearchQuery) as $constraintViolationList) {
            $actualPaths[] = $constraintViolationList->getPropertyPath();
        }

        $this->assertContains('filters[0].field', $actualPaths);
        $this->assertContains('filters[0].operator', $actualPaths);
    }
}
