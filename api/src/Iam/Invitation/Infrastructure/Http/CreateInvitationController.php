<?php

declare(strict_types=1);

namespace Erpify\Iam\Invitation\Infrastructure\Http;

use Erpify\Iam\Invitation\Application\SendInvitation;
use Erpify\Shared\Access\Domain\Role;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * `POST /api/v1/backoffice/invitations` — the management alta of the invitation flow: an ADMIN invites a new
 * member by email with roles, and {@see SendInvitation} provisions the `INVITED` identity, its membership and
 * the `SENT` invitation atomically, then emails the accept link post-commit. The controller is the thin adapter
 * the use case's docblock always anticipated (the CLI `iam:invitation:create` is the other one) — it maps the
 * validated wire strings to the {@see Role} enum and wraps the same call, adding nothing the CLI lacks (OCP).
 *
 * It lives in the Invitation context (beside {@see AcceptInvitationController}) rather than in Identity so it
 * wraps {@see SendInvitation} inside its own context with no cross-context import — a controller under
 * `Iam/Identity/` would introduce a new `Identity -> Invitation` seam and invert the dependency graph.
 *
 * The `201` body is intentionally empty: returning the invited identity would re-introduce that same seam
 * through the response, and {@see SendInvitation::invite} yields the plaintext accept token — a secret that
 * never leaves the email. The console refreshes the register to show the new `INVITED` row.
 *
 * Authorization is the controller-level `users.invite` gate over the custom `PermissionVoter`: putting the check
 * inside {@see SendInvitation} would couple the CLI, which invokes the same use case with no session. The literal
 * permission string mirrors the read-side register gate (`users.read`) — one action, no constants class yet.
 */
#[Route('/invitations', name: self::ROUTE_NAME, methods: ['POST'])]
#[IsGranted('users.invite')]
final readonly class CreateInvitationController
{
    public const string ROUTE_NAME = 'backoffice_invitation_create';

    public function __construct(private SendInvitation $sendInvitation)
    {
    }

    public function __invoke(#[MapRequestPayload] InviteUserRequest $request): Response
    {
        $this->sendInvitation->invite($request->email, ...$this->rolesFrom($request));

        return new Response(status: Response::HTTP_CREATED);
    }

    /**
     * @return list<Role>
     */
    private function rolesFrom(InviteUserRequest $request): array
    {
        return \array_map(Role::from(...), $request->roles);
    }
}
