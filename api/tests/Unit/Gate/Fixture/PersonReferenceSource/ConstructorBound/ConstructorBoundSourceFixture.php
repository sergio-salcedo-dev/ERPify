<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate\Fixture\PersonReferenceSource\ConstructorBound;

use Erpify\Shared\Privacy\Application\PersonReferenceSource;
use Erpify\Shared\Privacy\Domain\PersonReferenceAxis;
use Override;

/**
 * A source whose axis is not a constant fact of the class but an argument it was built with.
 *
 * The contract forbids that shape because the coverage gate reads the axis from the source tree, with no
 * container and no database. This fixture is what proves the engine says so instead of skipping the class:
 * a skip would leave the source's column silently uncovered while the class sits in the tree looking wired.
 *
 * @internal test fixture
 */
final readonly class ConstructorBoundSourceFixture implements PersonReferenceSource
{
    public function __construct(private string $axisKey)
    {
    }

    #[Override]
    public function axis(): PersonReferenceAxis
    {
        return PersonReferenceAxis::of($this->axisKey);
    }

    #[Override]
    public function retainedPersonIds(): array
    {
        return [];
    }
}
