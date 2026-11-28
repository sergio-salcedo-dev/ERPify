<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Domain\Repository;

use Erpify\Iam\Identity\Domain\Entity\TotpFactor;
use Erpify\Shared\Persistence\Domain\Exception\ConcurrentUniqueWrite;

/**
 * Aggregate-lifecycle port for {@see TotpFactor}.
 *
 * The lock order is USER FIRST, THEN FACTOR, then the factor's keystore row, on every path that takes them: the
 * enrolment and the confirmation lock `identity_user` (the enrolment to re-prove the password) before they read
 * this table under `FOR UPDATE`, and the identity erasure holds the user row before it reaches here. Sealing a
 * secret takes the keystore row last.
 */
interface TotpFactorRepository
{
    /**
     * @throws ConcurrentUniqueWrite when a concurrent first enrolment inserted the identity's row first
     */
    public function save(TotpFactor $factor): void;

    public function remove(TotpFactor $factor): void;

    /**
     * The identity's factor under `SELECT … FOR UPDATE`, re-hydrated from the locked row. Callable only inside a
     * transaction, and only after the identity's row is held.
     */
    public function findByUserIdForUpdate(string $userId): ?TotpFactor;
}
