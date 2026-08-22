<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate\Fixture\PersonReferenceSource\BackedByEnum;

use Erpify\Shared\Privacy\Application\PersonReferenceSource;
use Erpify\Shared\Privacy\Domain\PersonReferenceAxis;
use Erpify\Tests\Unit\Gate\Fixture\PersonReference\PersonReferenceFixtureEntity;
use Override;

/**
 * An enum carrying the contract, declaring an axis of its OWN so that failing to skip it is visible rather
 * than harmless — the same shape `AbstractSourceCarrier` pins for abstract classes, in the `Covered`
 * scenario next door.
 *
 * PHP enums may implement interfaces, and the container builds no service from one, so an axis an enum
 * declared would appear covered while nothing collects it. Skipping it is also what keeps the failure
 * legible: reflection refuses to instantiate an enum with an `Error`, which the scan's own catch would
 * otherwise relabel as "the axis cannot be read without constructing it" — true of a constructor-bound
 * source and misleading about this one.
 *
 * @internal test fixture
 */
enum EnumSourceFixture implements PersonReferenceSource
{
    #[Override]
    public function axis(): PersonReferenceAxis
    {
        return PersonReferenceAxis::of(PersonReferenceFixtureEntity::class . '::$tenantId');
    }

    #[Override]
    public function retainedPersonIds(): array
    {
        return [];
    }

    case Only;
}
