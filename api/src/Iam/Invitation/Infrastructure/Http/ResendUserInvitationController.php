<?php

declare(strict_types=1);

namespace Erpify\Iam\Invitation\Infrastructure\Http;

use Erpify\Iam\Invitation\Application\IssuedInvitation;
use Erpify\Iam\Invitation\Application\ResendInvitation;
use Erpify\Iam\Invitation\Domain\Exception\InvitationEmailNotSent;
use Erpify\Iam\Invitation\Domain\Exception\ResendableInvitationNotFound;
use Erpify\Iam\Invitation\Infrastructure\Security\InvitationResendThrottle;
use Erpify\Shared\Uuid\Domain\Uuid;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * `POST /api/v1/backoffice/users/{userId}/invitation/resend` — the console lever that re-delivers a pending
 * invitation with a fresh link, gated by `users.resendInvitation`. The previous link dies in the same
 * transaction, so this is also how an operator retires a link that went to the right person by the wrong route.
 *
 * Keyed by the USER and mounted beside {@see RevokeUserInvitationController} for the boundary reason that
 * controller states: the console holds a row of the Identity-owned users register, never an invitation id. POST
 * on an action sub-resource rather than a verb on `…/invitation` itself, because the act submits no
 * representation — the same shape `POST …/users/{id}/unlock` takes — and is not idempotent: each call mints a
 * new link and invalidates the last one.
 *
 * Nothing resendable — an already-active person, spent invitations, a withdrawn identity, an unknown id — is a
 * 404 {@see ResendableInvitationNotFound}; a malformed id is a 400 `invalid-uuid` from the use case's
 * `Uuid::ensure` before any lookup. Success answers `204 No Content`: the accept token is a credential that
 * leaves only in the email, so the body has nothing it may carry.
 *
 * A refused send is surfaced, not swallowed. By then the new link is committed and the old one is dead, so
 * answering 204 would tell the operator the invitee holds a working link when they hold none. It is a 503
 * {@see InvitationEmailNotSent}, and the remedy it names — resend again — is safe to repeat.
 *
 * {@see InvitationResendThrottle} runs before the use case, keyed by the invitee: each call kills the link the
 * person already holds, so the number of calls per person is bounded whoever makes them, and a spent budget is a
 * 429 `rate-limited` that reaches no row. It runs after `#[IsGranted]` and after the id is checked to be a UUID,
 * so only an administrator naming a well-formed id spends it, and every such attempt does, including one that
 * ends in a 404 or a 503.
 */
#[Route('/users/{userId}/invitation/resend', name: self::ROUTE_NAME, methods: ['POST'])]
#[IsGranted('users.resendInvitation')]
final readonly class ResendUserInvitationController
{
    public const string ROUTE_NAME = 'backoffice_user_invitation_resend';

    public function __construct(
        private ResendInvitation $resendInvitation,
        private InvitationResendThrottle $throttle,
    ) {
    }

    public function __invoke(string $userId): Response
    {
        Uuid::ensure($userId);
        $this->throttle->ensureWithinBudget($userId);

        $issued = $this->resendInvitation->resendForInvitedUser($userId);

        if (!$this->everyEmailAccepted($issued)) {
            throw new InvitationEmailNotSent();
        }

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    /**
     * @param list<IssuedInvitation> $issued
     */
    private function everyEmailAccepted(array $issued): bool
    {
        return \array_all($issued, static fn (IssuedInvitation $invitation): bool => $invitation->mailerAccepted);
    }
}
