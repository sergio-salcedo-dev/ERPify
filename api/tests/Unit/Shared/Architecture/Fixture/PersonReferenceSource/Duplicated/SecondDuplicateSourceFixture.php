<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Architecture\Fixture\PersonReferenceSource\Duplicated;

use Erpify\Shared\Privacy\Application\PersonReferenceSource;
use Erpify\Shared\Privacy\Domain\PersonReferenceAxis;
use Erpify\Tests\Unit\Shared\Architecture\Fixture\PersonReference\PersonReferenceFixtureEntity;
use Override;

/**
 * The other half of the collision. Its class name differs from its twin's, which is the point: a check
 * matching sources to columns by name similarity would see two unrelated classes and never notice they
 * cover the same column.
 *
 * @internal test fixture
 */
final readonly class SecondDuplicateSourceFixture implements PersonReferenceSource
{
    #[Override]
    public function axis(): PersonReferenceAxis
    {
        return PersonReferenceAxis::of(PersonReferenceFixtureEntity::class . '::$subjectId');
    }

    #[Override]
    public function retainedPersonIds(): array
    {
        return [];
    }
}
