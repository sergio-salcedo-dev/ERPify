<?php

declare(strict_types=1);

namespace Erpify\Iam\Invitation\Application;

use DateInterval;
use Erpify\Iam\Identity\Domain\Repository\UserRepository;
use Erpify\Iam\Invitation\Domain\Exception\InvitationNotFound;
use Erpify\Iam\Invitation\Domain\Repository\InvitationRepository;
use Erpify\Shared\Clock\Domain\Clock;
use Erpify\Shared\Event\Domain\EventBus;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Shared\Token\Domain\SingleUseToken;
use Erpify\Shared\Uuid\Domain\Uuid;
use RuntimeException;

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
        private InvitationEmailSender $emailSender,
        private EventBus $eventBus,
        private TransactionManager $transactionManager,
        private Clock $clock,
    ) {
    }

    /**
     * @throws InvitationNotFound when the id resolves to no invitation
     *
     * @return string the fresh `<invitationId>.<secret>` accept token, delivered once
     */
    public function resend(string $invitationId): string
    {
        Uuid::ensure($invitationId);

        $generated = SingleUseToken::mint($this->clock->now()->add(new DateInterval(self::TTL_SPEC)));

        $recipientEmail = $this->transactionManager->transactional(
            function () use ($invitationId, $generated): string {
                $invitation = $this->invitations->findById($invitationId)
                    ?? throw new InvitationNotFound($invitationId);

                $invitation->resend($generated->token);

                $this->invitations->save($invitation);
                $this->eventBus->publish(...$invitation->pullDomainEvents());

                $user = $this->users->findById($invitation->invitedUserId())
                    ?? throw new RuntimeException('The invited identity no longer exists.');

                return $user->email();
            },
        );

        $acceptToken = $invitationId . '.' . $generated->plaintext();
        $this->emailSender->send($recipientEmail, $acceptToken);

        return $acceptToken;
    }
}
