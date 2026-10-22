<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use Closure;
use DateTimeImmutable;
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

    /** Runs at each save, so a test can observe the state it happens in. */
    public ?Closure $onSave = null;

    /** Runs at each delete, so a test can observe the state it happens in. */
    public ?Closure $onDelete = null;

    public function __construct(ErasureResweep ...$resweeps)
    {
        $this->resweeps = \array_values($resweeps);
    }

    /**
     * An upsert on the subject, as the production adapter's `ON CONFLICT (subject_id)`: a second scheduling
     * restarts the window instead of adding a row, so it moves to the end of the read order.
     */
    #[Override]
    public function save(ErasureResweep $resweep): void
    {
        $this->resweeps = \array_values(\array_filter(
            $this->resweeps,
            static fn (ErasureResweep $held): bool => 0 !== \strcasecmp($held->subjectId(), $resweep->subjectId()),
        ));
        $this->resweeps[] = $resweep;

        if ($this->onSave instanceof Closure) {
            ($this->onSave)();
        }
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
    public function deleteForSubject(string $subjectId, DateTimeImmutable $scheduledAt): void
    {
        $this->resweeps = \array_values(\array_filter(
            $this->resweeps,
            static fn (ErasureResweep $held): bool => $held->subjectId() !== $subjectId
                || $held->getCreatedAt()->format('U.u') !== $scheduledAt->format('U.u'),
        ));
        $this->deletedSubjectIds[] = $subjectId;

        if ($this->onDelete instanceof Closure) {
            ($this->onDelete)();
        }
    }

    /**
     * @return list<string>
     */
    public function scheduledSubjectIds(): array
    {
        return \array_map(static fn (ErasureResweep $resweep): string => $resweep->subjectId(), $this->resweeps);
    }
}
