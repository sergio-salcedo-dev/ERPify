<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Domain\Repository;

use Erpify\Iam\Identity\Domain\Entity\ErasureResweep;

interface ErasureResweepRepository
{
    /**
     * Joins the caller's transaction: the erasure schedules its re-sweep inside the transaction that erases,
     * so a rolled-back erasure leaves nothing to re-sweep.
     */
    public function save(ErasureResweep $resweep): void;

    /**
     * Every scheduled re-sweep, oldest first.
     *
     * @return list<ErasureResweep>
     */
    public function findAll(): array;

    public function delete(ErasureResweep $resweep): void;
}
