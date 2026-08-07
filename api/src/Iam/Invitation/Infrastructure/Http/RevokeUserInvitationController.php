<?php

declare(strict_types=1);

namespace Erpify\Iam\Invitation\Infrastructure\Http;

use Erpify\Iam\Invitation\Application\RevokeInvitation;
use Erpify\Iam\Invitation\Domain\Exception\RevocableInvitationNotFound;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * `DELETE /api/v1/backoffice/users/{userId}/invitation` — the console lever that pulls a pending invitation
 * back, gated by `users.revokeInvitation`. It exists because revocation was reachable only from a container
 * shell: a rogue or mistaken invite otherwise sits at `status=INVITED, roles=[…]` with a live token for three
 * days, and {@see \Erpify\Iam\Invitation\Application\AcceptInvitation} never re-checks the inviter's standing.
 *
 * Keyed by the USER, not by the invitation, and that is a boundary decision rather than a convenience: the
 * console operates on a row of the users register, which is owned by `Iam/Identity` and knows nothing of
 * `iam_invitation`. Surfacing an invitation id through that read model would mint an `Identity → Invitation`
 * read seam where only an erasure write crosses today. Resolving the user's invitations inside this context
 * costs none, because `invited_user_id` is Invitation's own column.
 *
 * The plural follows from the schema: `invited_user_id` carries a plain index and no uniqueness, so nothing
 * constrains a user to one invitation and {@see RevokeInvitation::revokeForInvitedUser()} pulls every live
 * one atomically. Nothing revocable — an already-active person, spent invitations, an unknown id — is a 404
 * {@see RevocableInvitationNotFound} carried by the RFC 9457 pipeline; a malformed id is refused as a 400
 * `invalid-uuid` by the use case's `Uuid::ensure` before any lookup. Success leaves nothing to serialize, so
 * it answers `204 No Content` and the console reloads the row to show its new state.
 */
#[Route('/users/{userId}/invitation', name: self::ROUTE_NAME, methods: ['DELETE'])]
#[IsGranted('users.revokeInvitation')]
final readonly class RevokeUserInvitationController
{
    public const string ROUTE_NAME = 'backoffice_user_invitation_revoke';

    public function __construct(private RevokeInvitation $revokeInvitation)
    {
    }

    public function __invoke(string $userId): Response
    {
        $this->revokeInvitation->revokeForInvitedUser($userId);

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
