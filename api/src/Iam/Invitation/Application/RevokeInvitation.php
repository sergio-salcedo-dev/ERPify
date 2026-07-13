<?php

declare(strict_types=1);

namespace Erpify\Iam\Invitation\Application;

use Erpify\Iam\Invitation\Domain\Exception\InvitationNotFound;
use Erpify\Iam\Invitation\Domain\Repository\InvitationRepository;
use Erpify\Shared\Event\Domain\EventBus;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Shared\Uuid\Domain\Uuid;

/**
 * Revokes a live invitation (`SENT → REVOKED`): the previously delivered token stops working immediately (its
 * next presentation collapses to the opaque wall). One transaction, save-then-publish on the outbox.
 */
final readonly class RevokeInvitation
{
    public function __construct(
        private InvitationRepository $invitations,
        private EventBus $eventBus,
        private TransactionManager $transactionManager,
    ) {
    }

    /**
     * @throws InvitationNotFound when the id resolves to no invitation
     */
    public function revoke(string $invitationId): void
    {
        Uuid::ensure($invitationId);

        $this->transactionManager->transactional(function () use ($invitationId): void {
            $invitation = $this->invitations->findById($invitationId) ?? throw new InvitationNotFound($invitationId);

            $invitation->revoke();

            $this->invitations->save($invitation);
            $this->eventBus->publish(...$invitation->pullDomainEvents());
        });
    }
}
