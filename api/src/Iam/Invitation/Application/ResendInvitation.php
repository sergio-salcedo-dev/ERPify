<?php

declare(strict_types=1);

namespace Erpify\Iam\Invitation\Application;

use DateInterval;
use Erpify\Iam\Identity\Application\FulfilIdentityErasure;
use Erpify\Iam\Identity\Domain\Repository\UserRepository;
use Erpify\Iam\Invitation\Domain\Entity\Invitation;
use Erpify\Iam\Invitation\Domain\Exception\InvitationNotFound;
use Erpify\Iam\Invitation\Domain\Exception\InvitedIdentityUnavailable;
use Erpify\Iam\Invitation\Domain\Exception\ResendableInvitationNotFound;
use Erpify\Iam\Invitation\Domain\Repository\InvitationRepository;
use Erpify\Shared\Audit\Application\AuditLogger;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Domain\AuditResource;
use Erpify\Shared\Clock\Domain\Clock;
use Erpify\Shared\Event\Domain\EventBus;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Shared\Token\Domain\SingleUseToken;
use Erpify\Shared\Uuid\Domain\Uuid;
use LogicException;

/**
 * Re-sends a still-live invitation with a fresh token: the previous token is invalidated (its digest is
 * replaced), a new one is delivered by email, and {@see \Erpify\Iam\Invitation\Domain\Event\InvitationResent}
 * is published. The invitation stays `SENT`. Reusing the previous link after a resend collapses to the opaque
 * wall.
 *
 * Two entry points for the same act, for the reason {@see RevokeInvitation} has two: the CLI holds the
 * invitation id it was handed, while the console holds a row of the users register, which knows the person and
 * not the delivery record — so {@see resendForInvitedUser()} resolves the invitations here, inside the context
 * that owns `invited_user_id`.
 *
 * Each resend writes a `security` audit row naming the invitee, because the event store records that a link
 * was replaced and never by whom — and a resend is a write against a person who cannot see it coming. The row
 * is written inside the transaction, after the invitations are locked, and that lock is what keeps it erasable:
 * {@see FulfilIdentityErasure} deletes the subject's invitations before it passes over the trail, so its
 * delete waits for this transaction and the pass then finds the row committed, or it ran first and the locked
 * read here finds nothing to resend and writes nothing.
 *
 * The object coupling is the two entry points sharing one reissue: each adds the not-found answer its own
 * caller is owed, over the same token, mailer, event bus, audit and transaction collaborators.
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
final readonly class ResendInvitation
{
    private const string TTL_SPEC = 'P3D';

    private const string RESENT_ACTION = 'INVITATION_RESENT';

    public function __construct(
        private InvitationRepository $invitations,
        private UserRepository $users,
        private SendInvitationEmailBestEffort $emailSender,
        private EventBus $eventBus,
        private TransactionManager $transactionManager,
        private Clock $clock,
        private AuditLogger $auditLogger,
    ) {
    }

    /**
     * @throws InvitationNotFound         when the id resolves to no invitation
     * @throws InvitedIdentityUnavailable when the invitation names an identity that is gone or withdrawn
     */
    public function resend(string $invitationId): IssuedInvitation
    {
        Uuid::ensure($invitationId);

        [$recipientEmail, $acceptToken] = $this->transactionManager->transactional(
            function () use ($invitationId): array {
                $invitation = $this->invitations->findByIdForUpdate($invitationId)
                    ?? throw new InvitationNotFound($invitationId);

                $user = $this->users->findById($invitation->invitedUserId())
                    ?? throw InvitedIdentityUnavailable::missing();

                // The invitation's own `SENT` guard is not sufficient: revoking one of several invitations
                // addressed to the same user withdraws the identity once and leaves the others live, so a
                // `SENT` row can point at an identity that can never activate. Re-tokenising it would mail a
                // fresh link whose only possible outcome is the opaque wall.
                if (!$user->isInvited()) {
                    throw InvitedIdentityUnavailable::withdrawn();
                }

                $acceptToken = $this->reissue($invitation, $invitationId);
                $this->auditResend($invitation->invitedUserId());

                return [$user->email(), $acceptToken];
            },
        );

        return new IssuedInvitation($acceptToken, $this->emailSender->send($recipientEmail, $acceptToken));
    }

    /**
     * Re-sends EVERY still-live invitation addressed to a user, in one transaction, then mails each fresh link
     * after the commit. More than one is defensive rather than expected — no write path produces it, but
     * `invited_user_id` carries no uniqueness — and re-tokenising them all is the answer that leaves no row
     * behind holding a link the operator believes was replaced. Each returned outcome carries its own
     * `mailerAccepted`, so the caller can tell a delivered set from one the mailer refused.
     *
     * The read takes the row locks, so an invitee accepting concurrently drops out of the set rather than
     * raising an invalid transition; an accept that lands first leaves nothing to resend, which is the 404
     * below. An identity that is no longer `INVITED` is the same answer: from the console's side there is no
     * pending invitation to re-deliver, and mailing one would only ever meet the opaque wall.
     *
     * @throws ResendableInvitationNotFound when the user has no `SENT` invitation, or is no longer pending
     * @throws InvitedIdentityUnavailable   when the invitations name an identity that no longer exists — a
     *                                      desync no write path produces, surfaced rather than swallowed
     *
     * @return list<IssuedInvitation> one per re-sent invitation, never empty
     */
    public function resendForInvitedUser(string $userId): array
    {
        Uuid::ensure($userId);

        [$recipientEmail, $acceptTokens] = $this->transactionManager->transactional(
            function () use ($userId): array {
                $invitations = $this->invitations->findSentByInvitedUserForUpdate($userId);

                if ([] === $invitations) {
                    throw new ResendableInvitationNotFound($userId);
                }

                $user = $this->users->findById($userId) ?? throw InvitedIdentityUnavailable::missing();

                if (!$user->isInvited()) {
                    throw new ResendableInvitationNotFound($userId);
                }

                $acceptTokens = [];

                foreach ($invitations as $invitation) {
                    $acceptTokens[] = $this->reissue($invitation, $this->idOf($invitation));
                }

                $this->auditResend($userId);

                return [$user->email(), $acceptTokens];
            },
        );

        return \array_map(
            fn (string $acceptToken): IssuedInvitation => new IssuedInvitation(
                $acceptToken,
                $this->emailSender->send($recipientEmail, $acceptToken),
            ),
            $acceptTokens,
        );
    }

    /**
     * Swaps the invitation's digest for a fresh one and returns the raw `<selector>.<secret>` accept token — the
     * only copy of the secret, which leaves this class solely towards the email and the caller.
     */
    private function reissue(Invitation $invitation, string $selector): string
    {
        $generated = SingleUseToken::mint($this->clock->now()->add(new DateInterval(self::TTL_SPEC)));

        $invitation->resend($generated->token);

        $this->invitations->save($invitation);
        $this->eventBus->publish(...$invitation->pullDomainEvents());

        return $selector . '.' . $generated->plaintext();
    }

    /**
     * One row per act rather than per invitation: the operator resent a person's invitation, and the defensive
     * case of several live rows for one person is not a second decision.
     */
    private function auditResend(string $invitedUserId): void
    {
        $this->auditLogger->log(
            self::RESENT_ACTION,
            AuditLevel::SECURITY,
            AuditResource::of(FulfilIdentityErasure::SUBJECT_RESOURCE_TYPE, $invitedUserId),
        );
    }

    private function idOf(Invitation $invitation): string
    {
        return $invitation->getId() ?? throw new LogicException('A persisted invitation carries an id.');
    }
}
