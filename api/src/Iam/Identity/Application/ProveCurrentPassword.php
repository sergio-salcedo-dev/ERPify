<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application;

use Closure;
use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Domain\Exception\InvalidCurrentPassword;
use Erpify\Iam\Identity\Domain\Exception\InvalidHashedPassword;
use Erpify\Iam\Identity\Domain\HashedPassword;

/**
 * The one place an identity re-proves the credential it already holds, for the two use cases that demand it
 * from a live session: replacing the password ({@see ChangeMyPassword}) and minting a recovery secret
 * ({@see MintRecoverySecret}).
 *
 * **What is shared is a security policy, not four lines of convenience.** A stored credential that cannot be
 * read — absent, or corrupt enough that {@see User::passwordHash()} refuses it — is answered as a WRONG one
 * rather than as a 500. That is deliberate and it is the property worth stating once: the two states are
 * indistinguishable to whoever is typing, so a divergent answer would tell a caller which identities have an
 * unusable credential row. Reporting the corruption is the integrity probe's job, not this endpoint's. Two
 * copies of that reasoning are two places it can quietly stop agreeing.
 *
 * The comparison arrives as a closure the HTTP adapter builds, and it stays that way here: hashing and
 * verifying are algorithm knowledge belonging to Infrastructure, so neither this class nor anything it calls
 * ever holds the submitted plaintext. What crosses the boundary is a boolean.
 *
 * It is an Application collaborator rather than a method on {@see User} deliberately. Pushing it into the
 * aggregate would look like Tell-Don't-Ask, but the verification mechanism is supplied from Infrastructure as
 * a callback — so the aggregate would have to learn about the callback protocol purely to repair a use-case
 * interaction, which trades a small ask for a real dependency in the wrong direction.
 *
 * It takes the aggregate by parameter and holds no state, so it adds no lock, no transaction and no ordering
 * of its own: the caller has already loaded the identity under whatever lock its own flow requires, and this
 * runs inside that.
 */
final readonly class ProveCurrentPassword
{
    /**
     * @param Closure(HashedPassword): bool $verify whether the submitted current password is the stored one
     *
     * @throws InvalidCurrentPassword when it is not, when there is no credential, or when the stored one is
     *                                unreadable — one answer for all three
     */
    public function ensure(User $user, Closure $verify): void
    {
        // The `??` alone never fires on a corrupt row: `passwordHash()` raises before it can, and the
        // marker-less exception would land as a 500 where this plainly intends a refusal.
        try {
            $current = $user->passwordHash() ?? throw new InvalidCurrentPassword();
        } catch (InvalidHashedPassword) {
            throw new InvalidCurrentPassword();
        }

        if (!$verify($current)) {
            throw new InvalidCurrentPassword();
        }
    }
}
