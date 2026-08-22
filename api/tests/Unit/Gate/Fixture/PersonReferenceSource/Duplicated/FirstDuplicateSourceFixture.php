<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate\Fixture\PersonReferenceSource\Duplicated;

use Erpify\Shared\Privacy\Application\PersonReferenceSource;
use Erpify\Shared\Privacy\Domain\PersonReferenceAxis;
use Erpify\Tests\Unit\Gate\Fixture\PersonReference\PersonReferenceFixtureEntity;
use Override;

/**
 * One half of the collision: two sources claiming one axis, which the verdict's per-axis keying would
 * resolve by keeping whichever ran last.
 *
 * @internal test fixture
 */
final readonly class FirstDuplicateSourceFixture implements PersonReferenceSource
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
