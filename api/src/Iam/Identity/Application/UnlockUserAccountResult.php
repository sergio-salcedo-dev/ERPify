<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application;

use Erpify\Iam\Identity\Domain\Entity\User;

/**
 * Outcome of {@see UnlockUserAccount}: the identity as it stands once the call returns, and whether the call
 * actually cleared a lockout. {@see User::clearLockout()} is idempotent, so invoking it against an
 * already-unlocked identity is a legitimate, successful call — `unlocked` is what tells that apart from a
 * genuine recovery, rather than the caller inferring it from a diff of two reads or the endpoint fabricating
 * a recovery that never happened.
 */
final readonly class UnlockUserAccountResult
{
    public function __construct(
        public User $user,
        public bool $unlocked,
    ) {
    }
}
