<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application;

/**
 * Counts the two stored shapes the identity runtime tolerates on purpose, in the only place they are visible.
 *
 * Both are read on every authenticated request and both are handled without a word. A role value no `Role`
 * case backs is DISCARDED by {@see \Erpify\Iam\Identity\Domain\Entity\User::roles()}, because authorization is
 * grant-only and an unrecognised value can concede nothing; a credential
 * {@see \Erpify\Iam\Identity\Domain\HashedPassword} refuses becomes the same refusal an unknown email gets.
 * That tolerance is the right runtime behaviour and it is precisely why the state needs its own reader: the
 * preventive half proves the CODE is written, and only this proves the ROWS are clean — the same split
 * `api/.person-reference-policy` draws between an erasure that is written and one that ran.
 *
 * Reads the raw columns rather than the aggregate, and that is not an optimisation: `User::roles()` filters,
 * so an identity hydrated through the aggregate can never report the orphan value it is carrying.
 */
interface StoredIdentityIntegrity
{
    /**
     * The distinct role values stored against some identity that no live `Role` case backs, ordered so a
     * diffing alert cannot fire on row order. The known set is derived from the enum itself, never listed
     * beside it — a control that had to be edited whenever a case was retired would go stale exactly when
     * the drift it detects becomes possible.
     *
     * @throws StoredIdentityProbeFailed when the read fails, so the caller reaches no verdict
     *
     * @return list<string>
     */
    public function roleValuesOutsideTheEnum(): array;

    /**
     * How many identities hold a `roles` column that is not a JSON array at all — an object, a scalar, or the
     * literal `null`.
     *
     * Counted apart from the values above because it is a different fault with a different repair, and
     * because it is the one shape the aggregate cannot absorb: a scalar fails the entity's `array` property
     * type during hydration, so the identity cannot be loaded at all and every path that reads it raises
     * before any accessor runs. The read must therefore never expand such a row, or one corrupt cell would
     * cost the operator every other finding in the table.
     *
     * @throws StoredIdentityProbeFailed when the read fails, so the caller reaches no verdict
     */
    public function identitiesWithMalformedRoles(): int;

    /**
     * How many identities hold a credential the value object refuses.
     *
     * A count and NOT the ids, deliberately: what this control owes is whether the state is clean, and an
     * identity's id is a person reference. Listing them would print person ids into an operator's terminal
     * and into the log of anything that comes to run it unattended — a sink this repo classifies everywhere
     * else, opened here for no gain, since the repair needs a query against the table either way.
     *
     * A null `password_hash` is NOT counted: an `INVITED` identity has no credential yet, which is a
     * lifecycle state and not corruption.
     *
     * @throws StoredIdentityProbeFailed when the read fails, so the caller reaches no verdict
     */
    public function identitiesWithAnUnreadableCredential(): int;

    /**
     * How many identities past the credential-less lifecycle states hold no credential at all.
     *
     * The quietest of the three, and the reason it is counted: nothing refuses it loudly. `HashedPassword` is
     * never asked, so no exception exists to catch; the firewall's credentials check simply reads a null
     * password as "cannot authenticate" and answers the same uniform 401 a wrong password gets. The owner is
     * locked out of an `ACTIVE` account with no signal telling them or the operator apart from a typo, and
     * every other control in the repository stays green.
     *
     * `INVITED` and `REVOKED` are excluded because for them the null IS the modelled state, not a fault.
     *
     * @throws StoredIdentityProbeFailed when the read fails, so the caller reaches no verdict
     */
    public function admittedIdentitiesWithoutACredential(): int;
}
