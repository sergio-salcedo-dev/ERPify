<?php

declare(strict_types=1);

namespace Erpify\Iam\Invitation\Application;

use DateInterval;
use Erpify\Iam\Identity\Domain\Repository\UserRepository;
use Erpify\Iam\Invitation\Domain\Exception\InvitationNotFound;
use Erpify\Iam\Invitation\Domain\Exception\InvitedIdentityUnavailable;
use Erpify\Iam\Invitation\Domain\Repository\InvitationRepository;
use Erpify\Shared\Clock\Domain\Clock;
use Erpify\Shared\Event\Domain\EventBus;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Shared\Token\Domain\SingleUseToken;
use Erpify\Shared\Uuid\Domain\Uuid;

/**
 * Re-sends a still-live invitation with a fresh token: the previous token is invalidated (its digest is
 * replaced), a new one is delivered by email, and {@see \Erpify\Iam\Invitation\Domain\Event\InvitationResent}
 * is published. The invitation stays `SENT`. Reusing the previous link after a resend collapses to the opaque
 * wall.
 */
final readonly class ResendInvitation
{
    private const string TTL_SPEC = 'P3D';

    public function __construct(
        private InvitationRepository $invitations,
        private UserRepository $users,
        private SendInvitationEmailBestEffort $emailSender,
        private EventBus $eventBus,
        private TransactionManager $transactionManager,
        private Clock $clock,
    ) {
    }

    /**
     * @throws InvitationNotFound when the id resolves to no invitation
     */
    public function resend(string $invitationId): IssuedInvitation
    {
        Uuid::ensure($invitationId);

        $generated = SingleUseToken::mint($this->clock->now()->add(new DateInterval(self::TTL_SPEC)));

        $recipientEmail = $this->transactionManager->transactional(
            function () use ($invitationId, $generated): string {
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

                $invitation->resend($generated->token);

                $this->invitations->save($invitation);
                $this->eventBus->publish(...$invitation->pullDomainEvents());

                return $user->email();
            },
        );

        $acceptToken = $invitationId . '.' . $generated->plaintext();

        return new IssuedInvitation($acceptToken, $this->emailSender->send($recipientEmail, $acceptToken));
    }
}
