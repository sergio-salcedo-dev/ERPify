<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Domain\Repository;

use Erpify\Iam\Identity\Domain\Entity\ErasureResweep;

interface ErasureResweepRepository
{
    /**
     * Joins the caller's transaction: the erasure schedules its re-sweep inside the transaction that erases,
     * so a rolled-back erasure leaves nothing to re-sweep. A subject already scheduled has its window
     * restarted rather than raising.
     */
    public function save(ErasureResweep $resweep): void;

    /**
     * Every scheduled re-sweep, oldest first.
     *
     * @return list<ErasureResweep>
     */
    public function findAll(): array;

    /**
     * By the subject's id rather than by the entity, so a row read before an earlier transaction of the same
     * tick rolled back — and the EntityManager was reset under it — can still be deleted.
     */
    public function deleteForSubject(string $subjectId): void;
}
