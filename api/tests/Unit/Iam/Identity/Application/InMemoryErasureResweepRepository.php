<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use Erpify\Iam\Identity\Domain\Entity\ErasureResweep;
use Erpify\Iam\Identity\Domain\Repository\ErasureResweepRepository;
use Override;

/**
 * In-memory {@see ErasureResweepRepository} keeping insertion order, which is the order the production
 * adapter returns for rows scheduled at distinct instants.
 *
 * @internal
 */
final class InMemoryErasureResweepRepository implements ErasureResweepRepository
{
    /** @var list<ErasureResweep> */
    private array $resweeps;

    /** @var list<string> subject ids, in the order their rows were deleted */
    public array $deletedSubjectIds = [];

    public function __construct(ErasureResweep ...$resweeps)
    {
        $this->resweeps = \array_values($resweeps);
    }

    #[Override]
    public function save(ErasureResweep $resweep): void
    {
        $this->resweeps[] = $resweep;
    }

    /**
     * @return list<ErasureResweep>
     */
    #[Override]
    public function findAll(): array
    {
        return $this->resweeps;
    }

    #[Override]
    public function delete(ErasureResweep $resweep): void
    {
        $this->resweeps = \array_values(\array_filter(
            $this->resweeps,
            static fn (ErasureResweep $held): bool => $held !== $resweep,
        ));
        $this->deletedSubjectIds[] = $resweep->subjectId();
    }

    /**
     * @return list<string>
     */
    public function scheduledSubjectIds(): array
    {
        return \array_map(static fn (ErasureResweep $resweep): string => $resweep->subjectId(), $this->resweeps);
    }
}
