<?php

declare(strict_types=1);

namespace Erpify\Iam\Invitation\Application;

use Erpify\Iam\Identity\Domain\Exception\UserNotFound;
use Erpify\Iam\Identity\Domain\Repository\UserRepository;
use Erpify\Iam\Invitation\Domain\Entity\Invitation;
use Erpify\Iam\Invitation\Domain\Exception\InvitationNotFound;
use Erpify\Iam\Invitation\Domain\Exception\RevocableInvitationNotFound;
use Erpify\Iam\Invitation\Domain\Repository\InvitationRepository;
use Erpify\Shared\Event\Domain\EventBus;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Shared\Uuid\Domain\Uuid;

/**
 * Revokes a live invitation (`SENT → REVOKED`) and withdraws the identity it provisioned (`INVITED →
 * REVOKED`): the previously delivered token stops working immediately (its next presentation collapses to the
 * opaque wall), and the register stops showing a person as pending who can never activate. One transaction,
 * save-then-publish on the outbox, both aggregates together — the shape {@see AcceptInvitation} already uses
 * for the other terminal transition of the same pair.
 *
 * Withdrawing the identity is not bookkeeping. Without it the invitation dies alone and `identity_user` keeps
 * a row at `INVITED` carrying the roles that were being granted — including `ADMIN`, which is the invitation a
 * revocation is most likely to be pulling — for as long as the installation lives. Deleting the row instead
 * was rejected: the invitation already put this id in the reproducible log and in the membership that admitted
 * it, so a hard delete would strand references whose erasure has a declared owner.
 *
 * Two entry points for the same act, because two callers name their target differently. The CLI holds the
 * invitation id it just listed; the console holds a row of the users register, which knows the person and not
 * the delivery record — so {@see revokeForInvitedUser()} resolves the invitations here, inside the context
 * that owns `invited_user_id`, rather than surfacing an invitation id through the Identity-owned read model.
 */
final readonly class RevokeInvitation
{
    public function __construct(
        private InvitationRepository $invitations,
        private UserRepository $users,
        private EventBus $eventBus,
        private TransactionManager $transactionManager,
    ) {
    }

    /**
     * @throws InvitationNotFound when the id resolves to no invitation
     * @throws UserNotFound       when the invitation names an identity that no longer exists — a desync no
     *                            write path produces, surfaced rather than swallowed
     */
    public function revoke(string $invitationId): void
    {
        Uuid::ensure($invitationId);

        $this->transactionManager->transactional(function () use ($invitationId): void {
            $invitation = $this->invitations->findById($invitationId) ?? throw new InvitationNotFound($invitationId);

            $this->revokeOne($invitation);
            $this->withdrawIdentity($invitation->invitedUserId());
        });
    }

    /**
     * Revokes EVERY still-live invitation addressed to a user, in one transaction. Several is the shape the
     * schema admits — `invited_user_id` carries a plain index and no uniqueness — and under incident response
     * refusing on more than one would be the worst answer available: it would leave the extra tokens live.
     * All-or-nothing, so a failure on the n-th rolls the earlier revocations back and publishes nothing; a
     * responder is never told "revoked" over a partially-pulled set.
     *
     * The read takes the row locks (see the port), so an invitee accepting concurrently drops out of the set
     * instead of raising an invalid transition that would abandon the rest. An accept that lands before the
     * lock leaves nothing revocable, which is the 404 below — and the console reload then shows `ACTIVE`,
     * the true state and the responder's cue.
     *
     * More than one is defensive rather than expected: no write path produces it. `SendInvitation` mints a
     * fresh identity per invite and `identity_user.email` is unique, while `ResendInvitation` re-tokenises the
     * same row in place. The loop exists because the schema admits what the code does not — `invited_user_id`
     * carries a plain index and no uniqueness — and a responder must never be left holding a live token that a
     * single-row read happened to miss.
     *
     * @throws RevocableInvitationNotFound when the user has no `SENT` invitation left to pull
     * @throws UserNotFound                when the invitations name an identity that no longer exists
     */
    public function revokeForInvitedUser(string $userId): void
    {
        Uuid::ensure($userId);

        $this->transactionManager->transactional(function () use ($userId): void {
            $invitations = $this->invitations->findSentByInvitedUserForUpdate($userId);

            if ([] === $invitations) {
                throw new RevocableInvitationNotFound($userId);
            }

            foreach ($invitations as $invitation) {
                $this->revokeOne($invitation);
            }

            $this->withdrawIdentity($userId);
        });
    }

    private function revokeOne(Invitation $invitation): void
    {
        $invitation->revoke();

        $this->invitations->save($invitation);
        $this->eventBus->publish(...$invitation->pullDomainEvents());
    }

    /**
     * Withdraws the identity once per call, never once per invitation: the transition is guarded `INVITED →
     * REVOKED`, so a second application would raise an invalid transition and abort a revocation that had
     * already succeeded. The row is locked for the same reason the invitations are — an accept that lands
     * first must lose the race deterministically rather than leave the pair disagreeing.
     */
    private function withdrawIdentity(string $userId): void
    {
        $user = $this->users->findByIdForUpdate($userId) ?? throw UserNotFound::withId($userId);

        $user->revokeInvitation();

        $this->users->save($user);
        $this->eventBus->publish(...$user->pullDomainEvents());
    }
}
