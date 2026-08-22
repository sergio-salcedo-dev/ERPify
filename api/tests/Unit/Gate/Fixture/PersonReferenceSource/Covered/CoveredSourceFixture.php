<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate\Fixture\PersonReferenceSource\Covered;

use Erpify\Shared\Privacy\Application\PersonReferenceSource;
use Erpify\Shared\Privacy\Domain\PersonReferenceAxis;
use Erpify\Tests\Unit\Gate\Fixture\PersonReference\PersonReferenceFixtureEntity;
use Override;

/**
 * A well-formed source: it covers one axis and reports it without needing its constructor, which is what the
 * scan has to be able to read over the real tree.
 *
 * @internal test fixture
 */
final readonly class CoveredSourceFixture implements PersonReferenceSource
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
