<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Architecture\Fixture\PersonReferenceSource\Covered;

use Erpify\Shared\Privacy\Application\PersonReferenceSource;
use Erpify\Shared\Privacy\Domain\PersonReferenceAxis;
use Erpify\Tests\Unit\Shared\Architecture\Fixture\PersonReference\PersonReferenceFixtureEntity;
use Override;

/**
 * An abstract carrier of the contract, declaring an axis of its OWN so that failing to skip it is visible
 * rather than harmless: the container builds no service from it, so an axis it declared would appear covered
 * while nothing collects it.
 *
 * @internal test fixture
 */
abstract readonly class AbstractSourceCarrier implements PersonReferenceSource
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
}
