<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Search\Domain\Exception;

use Erpify\Shared\Domain\Exception\DomainException;
use Erpify\Shared\Domain\Exception\InvalidSearchCriteria;
use Erpify\Shared\Search\Domain\Exception\UnknownSortField;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(UnknownSortField::class)]
final class UnknownSortFieldTest extends TestCase
{
    public function testExposesKebabCaseWireType(): void
    {
        $unknownSortField = UnknownSortField::named('shoeSize');

        $this->assertSame(UnknownSortField::TYPE, $unknownSortField->type());
        $this->assertSame('unknown-sort-field', $unknownSortField->type());
        $this->assertSame('Unknown sort field.', $unknownSortField->title());
    }

    public function testImplementsInvalidSearchCriteriaMarkerOnDomainException(): void
    {
        // Runtime-derived pins: assertInstanceOf would be constant-folded by PHPStan
        // (method.alreadyNarrowedType) since the declaration already proves it.
        $this->assertContains(InvalidSearchCriteria::class, (array) \class_implements(UnknownSortField::class));
        $this->assertContains(DomainException::class, (array) \class_parents(UnknownSortField::class));
    }

    public function testContextCarriesTheOffendingPublicFieldName(): void
    {
        $unknownSortField = UnknownSortField::named('shoeSize');

        $this->assertSame(['field' => 'shoeSize'], $unknownSortField->context());
    }
}
