<?php

declare(strict_types=1);

namespace Erpify\Iam\Invitation\Application;

use DateInterval;
use Erpify\Iam\Identity\Application\InviteUser;
use Erpify\Iam\Identity\Domain\Enum\Role;
use Erpify\Iam\Invitation\Domain\Entity\Invitation;
use Erpify\Iam\Invitation\Domain\Exception\InvitedIdentityUnavailable;
use Erpify\Iam\Invitation\Domain\Repository\InvitationRepository;
use Erpify\Organization\Membership\Application\GrantMembership;
use Erpify\Shared\Clock\Domain\Clock;
use Erpify\Shared\Event\Domain\EventBus;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Shared\Token\Domain\SingleUseToken;
use Erpify\Shared\Uuid\Domain\Uuid;

/**
 * Orchestrates a new invitation across three contexts, in ONE transaction: it provisions the `INVITED` identity
 * ({@see InviteUser}), funnels it through {@see GrantMembership} so the user never exists without exactly one
 * membership, then mints the {@see Invitation} (`CREATED → SENT`) carrying the token digest — and publishes
 * its events on the outbox. The email is sent AFTER commit (its plaintext token never touches the transaction or
 * a domain event).
 *
 * The single reusable use case behind both the CLI now and the management HTTP surface later (OCP): the command
 * is a thin adapter that prints the returned token; a future endpoint wraps the very same call. The raw
 * `<invitationId>.<secret>` composite is returned once so the CLI can echo it, mirroring the email — never
 * logged, never persisted.
 *
 * The object coupling is essential complexity: an atomic onboarding necessarily coordinates three contexts
 * (identity provisioning, membership grant, invitation) plus the token, mailer, event bus and transaction —
 * each a published seam or core building block. Splitting it would fracture the single transaction that is its
 * whole point, so the coupling is accepted rather than diffused through indirection.
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
final readonly class SendInvitation
{
    private const string TTL_SPEC = 'P3D';

    public function __construct(
        private InviteUser $inviteUser,
        private GrantMembership $grantMembership,
        private InvitationRepository $invitations,
        private InvitationEmailSender $emailSender,
        private EventBus $eventBus,
        private TransactionManager $transactionManager,
        private Clock $clock,
    ) {
    }

    /**
     * @return string the raw `<invitationId>.<secret>` accept token, delivered once
     */
    public function invite(string $email, Role ...$roles): string
    {
        $invitationId = Uuid::generate();
        $generated = SingleUseToken::mint($this->clock->now()->add(new DateInterval(self::TTL_SPEC)));

        $recipientEmail = $this->transactionManager->transactional(
            function () use ($invitationId, $email, $generated, $roles): string {
                $user = $this->inviteUser->invite($email, ...$roles);
                $userId = $user->getId() ?? throw InvitedIdentityUnavailable::withoutId();
                $membership = $this->grantMembership->grant($userId, ...$roles);

                $invitation = Invitation::create(
                    $invitationId,
                    $membership->organizationId(),
                    $userId,
                    $generated->token,
                );
                $invitation->markSent();

                $this->invitations->save($invitation);
                $this->eventBus->publish(...$invitation->pullDomainEvents());

                return $user->email();
            },
        );

        $acceptToken = $invitationId . '.' . $generated->plaintext();
        $this->emailSender->send($recipientEmail, $acceptToken);

        return $acceptToken;
    }
}
