<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Privacy\Infrastructure\Double;

use Erpify\Shared\Privacy\Application\PersonReferenceSource;
use Erpify\Shared\Privacy\Domain\PersonReferenceAxis;
use Override;

/**
 * In-tree fake {@see PersonReferenceSource} returning a fixed axis and id list — the executable spec of a
 * contract that is deliberately narrow: it lists what a context stores and knows nothing about whether any
 * of it is still a live person.
 *
 * Its axis comes from the constructor, which the contract forbids for the implementations under `src/`
 * because the coverage gate reads those by reflection without building them. A double is never in that
 * sweep, and taking the axis as an argument is what lets one class stand in for several axes — which is
 * exactly what a test asserting that two axes are not merged needs.
 *
 * A named class rather than an anonymous one: PDepend (behind PHPMD) cannot parse `new readonly class`,
 * which is what the fixers rewrite an inline double into.
 *
 * @internal
 */
final readonly class FixedPersonReferenceSource implements PersonReferenceSource
{
    /**
     * @param list<string> $ids
     */
    public function __construct(private string $axisKey, private array $ids)
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
        return $this->ids;
    }
}
