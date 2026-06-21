<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Search\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;
use Erpify\Shared\ErrorContract\Domain\Exception\InvalidSearchCriteria;
use Erpify\Shared\Search\Domain\Exception\UnknownSearchField;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(UnknownSearchField::class)]
final class UnknownSearchFieldTest extends TestCase
{
    public function testExposesKebabCaseWireType(): void
    {
        $unknownSearchField = UnknownSearchField::named('shoeSize');

        $this->assertSame(UnknownSearchField::TYPE, $unknownSearchField->type());
        $this->assertSame('unknown-search-field', $unknownSearchField->type());
        $this->assertSame('Unknown search field.', $unknownSearchField->title());
    }

    public function testImplementsInvalidSearchCriteriaMarkerOnDomainException(): void
    {
        // Runtime-derived pins: assertInstanceOf would be constant-folded by PHPStan
        // (method.alreadyNarrowedType) since the declaration already proves it.
        $this->assertContains(InvalidSearchCriteria::class, (array) \class_implements(UnknownSearchField::class));
        $this->assertContains(DomainException::class, (array) \class_parents(UnknownSearchField::class));
    }

    public function testContextCarriesTheOffendingPublicFieldName(): void
    {
        $unknownSearchField = UnknownSearchField::named('shoeSize');

        $this->assertSame(['field' => 'shoeSize'], $unknownSearchField->context());
    }
}
