<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application;

use Closure;
use Erpify\Iam\Identity\Domain\Exception\InvalidCurrentPassword;
use Erpify\Iam\Identity\Domain\HashedPassword;
use Erpify\Iam\Identity\Domain\Repository\UserRepository;

/**
 * Makes the ACTING identity re-prove its own current password before it exercises administrative authority
 * over somebody else — handing out the administrator role, or changing the roles or the status of another
 * identity.
 *
 * **Why these acts re-prove, when they create no credential of the caller's.** A stolen administrator session
 * can plant a second administrator, or suspend or demote the real owner, and neither act is undone by evicting
 * the session that performed it: the planted administrator holds sessions of its own, and a suspended owner's
 * next redemption is refused. The caller's password is the one thing a stolen session does not hold, so asking
 * for it turns "whoever holds the cookie" into "whoever holds the cookie AND the password".
 *
 * **It is a collaborator the controller calls, and not a step inside the use cases it precedes**: the
 * invitation use case is also driven from the console as the operator (`iam:invitation:create`), so a proof
 * inside it would either refuse the console or need a way to be switched off, and a switch is exactly what a
 * stolen session would look for. The roles and status use cases have no other caller; the proof sits beside
 * the invitation's so the rule has one reading. `api/.credential-proof-policy` holds the membership of the
 * routes that call it.
 *
 * The identity is read WITHOUT a row lock: the proof reads one column and takes no decision that outlives it,
 * so serialising it against the rows the mutation locks would only widen the lock-order surface. An identity
 * that no longer exists is answered as a wrong password, the same as every other state in which the stored
 * credential cannot be matched ({@see ProveCurrentPassword}).
 */
final readonly class ProveActorPassword
{
    public function __construct(
        private UserRepository $users,
        private ProveCurrentPassword $proveCurrentPassword,
    ) {
    }

    /**
     * @param Closure(HashedPassword): bool $verify whether the submitted current password is the stored one
     *
     * @throws InvalidCurrentPassword when it is not, or when the acting identity has no readable credential
     */
    public function ensure(string $actorId, Closure $verify): void
    {
        $actor = $this->users->findById($actorId) ?? throw new InvalidCurrentPassword();

        $this->proveCurrentPassword->ensure($actor, $verify);
    }
}
