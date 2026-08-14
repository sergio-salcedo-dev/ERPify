<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Domain\Repository;

/**
 * Consumer-owned port over the organization's administrators, answering the two questions the write-side use
 * cases must ask before they change one: would at least one active `ADMIN` remain if this identity were
 * excluded, and does this identity carry the role at all?
 *
 * Both return a bare `bool` — no `Membership` / `User` / `Role[]` crosses the boundary. The single-tenant
 * organization is implicit. The production adapter reads the operational role source directly — a
 * single-context read over `identity_user`, which owns roles today — and re-points to `Membership` only if
 * tenancy ever moves the authoritative source.
 */
interface ActiveAdministratorDirectory
{
    /**
     * Takes the set lock without asking the question, so the caller can acquire it BEFORE the single
     * `identity_user` row it is about to change.
     *
     * It exists because the order those two locks are taken in is the whole invariant, and getting it wrong
     * is silent. {@see keepsAnActiveAdminWithout()} locks the active-admin set in `id` order; a use case that
     * has already locked its target row holds one member of that set out of order — the target is a member
     * whenever it is an ACTIVE administrator, `ADMIN` alone is not enough — so two concurrent transitions on
     * administrators X and Y (`X.id < Y.id`) each hold one and wait for the other: an ABBA deadlock, surfacing
     * as `40P01` and a 503 that nothing in the code explains.
     *
     * Taking the set first closes that cycle **between the transitions that take this lock**: each acquires the
     * whole set before its target, so any two of them serialise on the set and neither can be mid-scan while
     * the other holds a member.
     *
     * **The scope of that guarantee is exactly its wording, and the residual is real.** A writer that makes a
     * row join the set without taking this lock reopens the window: `Iam\Invitation`'s `AcceptInvitation`
     * turns an `INVITED` identity carrying `ADMIN` into an `ACTIVE` administrator on an unlocked path, so a
     * transition whose set statement already ran can find a lower-id member appearing under a third one. It is
     * deliberately not fixed here: that use case holds its row across a password KDF, and holding the whole
     * administrator set across a KDF trades a rare deadlock for a routine one. It also means a later
     * {@see keepsAnActiveAdminWithout()} is not merely a re-read: it runs its own statement under a new READ
     * COMMITTED snapshot, so a row that joined the set since is a genuine second acquisition.
     *
     * **Must run inside the caller's transaction**, as the first statement that touches `identity_user` — a
     * lock taken outside one is released at the end of its statement, and one taken after the row lock orders
     * nothing that has not already happened.
     */
    public function lockActiveAdministrators(): void;

    /**
     * Authoritative only over administrators whose backing `User` both exists AND is `ACTIVE`: an identity
     * that is absent or no longer `ACTIVE` must never keep a phantom administrator alive. Its adapter takes a
     * `FOR UPDATE` lock over the active-admin set so concurrent transitions serialize — the invariant is
     * set-based, so it must be read inside the caller's transaction.
     */
    public function keepsAnActiveAdminWithout(string $userId): bool;

    /**
     * Whether this identity carries `ADMIN`, regardless of its status — a suspended administrator still holds
     * the role. A per-subject precondition rather than a set invariant, and it takes NO lock, so its answer is
     * only true of the instant it was asked. That makes it a fast refusal, never a guarantee: use it to reject
     * early and cheaply, and {@see holdsAdministratorRoleForUpdate()} to decide.
     */
    public function holdsAdministratorRole(string $userId): bool;

    /**
     * The same question asked of the subject's row under `FOR UPDATE`, so the answer still holds at commit.
     *
     * The unlocked reading cannot carry this precondition: between it and the `DELETE` it guards, a concurrent
     * grant commits and an administrator is erased having never been demoted — with no `USER_ROLES_CHANGED`
     * ahead of it in the trail, which is the whole reason the refusal exists.
     *
     * **Must run inside the caller's transaction**, like the two locking members above: outside one Postgres
     * releases the lock at statement end and this silently becomes the unlocked reading again.
     *
     * **It takes a single `identity_user` row without the set lock, and that is the second sanctioned
     * exception to the rule stated above.** It is safe for a reason the accept path's is not: the erasure
     * acquires exactly one row on this table and never waits on a second, so it can block a set scan but can
     * never be one end of a cycle.
     *
     * Two boundaries it does not cover, stated because the sentence above ("still holds at commit") reads
     * wider than it is. A `FOR UPDATE` matching NO row takes no lock, so an absent subject is answered
     * `false` and locked against nothing — harmless only because every creation path mints a server-side
     * UUID, so no concurrent `INSERT` can claim that id. And the lock is not free on the paths that matter:
     * on a refusal nothing is deleted, so it is held on a live administrator's row until the rollback.
     */
    public function holdsAdministratorRoleForUpdate(string $userId): bool;
}
