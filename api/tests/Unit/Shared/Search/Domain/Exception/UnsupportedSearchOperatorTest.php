<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Search\Domain\Exception;

use Erpify\Shared\Domain\Exception\DomainException;
use Erpify\Shared\Domain\Exception\InvalidSearchCriteria;
use Erpify\Shared\Search\Domain\Exception\UnsupportedSearchOperator;
use Erpify\Shared\Search\Domain\FilterOperator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(UnsupportedSearchOperator::class)]
final class UnsupportedSearchOperatorTest extends TestCase
{
    public function testExposesKebabCaseWireType(): void
    {
        $unsupportedSearchOperator = UnsupportedSearchOperator::forField('id', FilterOperator::Contains);

        $this->assertSame(UnsupportedSearchOperator::TYPE, $unsupportedSearchOperator->type());
        $this->assertSame('unsupported-search-operator', $unsupportedSearchOperator->type());
        $this->assertSame('Unsupported search operator.', $unsupportedSearchOperator->title());
    }

    public function testImplementsInvalidSearchCriteriaMarkerOnDomainException(): void
    {
        // Runtime-derived pins: assertInstanceOf would be constant-folded by PHPStan
        // (method.alreadyNarrowedType) since the declaration already proves it.
        $this->assertContains(
            InvalidSearchCriteria::class,
            (array) \class_implements(UnsupportedSearchOperator::class),
        );
        $this->assertContains(DomainException::class, (array) \class_parents(UnsupportedSearchOperator::class));
    }

    public function testContextCarriesFieldAndWireOperatorToken(): void
    {
        $unsupportedSearchOperator = UnsupportedSearchOperator::forField('id', FilterOperator::Contains);

        // The wire token (enum backing string), never the enum instance — a FilterOperator
        // instance is not JsonSerializable and would be replaced by the factory's
        // '[unserializable]' sentinel in the Problem Details extensions.
        $this->assertSame(
            ['field' => 'id', 'operator' => 'contains'],
            $unsupportedSearchOperator->context(),
        );
    }
}
