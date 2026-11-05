<?php

declare(strict_types=1);

namespace Erpify\Iam\Invitation\Infrastructure\Http;

use Erpify\Iam\Identity\Application\ProveActorPassword;
use Erpify\Iam\Identity\Domain\HashedPassword;
use Erpify\Iam\Identity\Infrastructure\Security\CurrentPasswordProofThrottle;
use Erpify\Iam\Identity\Infrastructure\Security\PasswordHasher;
use Erpify\Iam\Identity\Infrastructure\Security\SecurityUser;
use Erpify\Iam\Invitation\Application\SendInvitation;
use Erpify\Shared\Access\Domain\Role;
use Erpify\Shared\Http\Infrastructure\StrictRequestPayload;
use LogicException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
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
 *
 * A second, narrower check rides on the payload rather than the route: minting an administrator is a distinct
 * act from inviting a member, so an invite whose role set carries `ADMIN` additionally demands
 * `users.grantAdmin`. It is imperative because it is conditional — `#[IsGranted]` would refuse every invite,
 * including the non-admin ones the actor is entitled to send. It stays here rather than in
 * {@see SendInvitation} for the same reason the route gate does: the CLI drives that use case with no session.
 *
 * **Minting an administrator also re-proves the CALLER's current password.** A stolen administrator session
 * could otherwise invite an address it controls with `ADMIN`, and evicting that session would not reach the
 * administrator it planted. The proof is reserved for the payload shape that hands the role out — the payload
 * alone decides it, so there is no read of any row between the decision and the act. Inviting a member who is
 * not an administrator stays free: it provisions an identity that cannot sign in until the mailbox holder
 * accepts. The budget is the per-identity one the credential routes share
 * ({@see CurrentPasswordProofThrottle}), spent after the permission checks so a refused caller spends nothing,
 * and the password travels in the body. See {@see ProveActorPassword} for why the proof is a collaborator here
 * and not a step inside {@see SendInvitation}.
 */
#[Route('/invitations', name: self::ROUTE_NAME, methods: ['POST'])]
#[IsGranted('users.invite')]
final readonly class CreateInvitationController
{
    public const string ROUTE_NAME = 'backoffice_invitation_create';

    private const string GRANT_ADMIN_PERMISSION = 'users.grantAdmin';

    public function __construct(
        private SendInvitation $sendInvitation,
        private ProveActorPassword $proveActorPassword,
        private PasswordHasher $passwordHasher,
        private CurrentPasswordProofThrottle $currentPasswordProofThrottle,
        private AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    public function __invoke(
        #[CurrentUser]
        SecurityUser $actor,
        #[StrictRequestPayload]
        InviteUserRequest $request,
    ): Response {
        $roles = $this->rolesFrom($request);

        if ($this->grantsAdmin($roles) && !$this->authorizationChecker->isGranted(self::GRANT_ADMIN_PERMISSION)) {
            // The message travels as the RFC 9457 `title`, so it names the refused act and no internals.
            throw new AccessDeniedException('You may not grant the ADMIN role.');
        }

        if ($this->grantsAdmin($roles)) {
            $actorId = $actor->id() ?? throw new LogicException('An authenticated identity must have an id.');

            $this->currentPasswordProofThrottle->ensureWithinBudget($actorId);

            $this->proveActorPassword->ensure(
                $actorId,
                fn (HashedPassword $stored): bool => $this->passwordHasher->verify(
                    $request->currentPassword,
                    $stored->toString(),
                ),
            );
        }

        $this->sendInvitation->invite($request->email, ...$roles);

        return new Response(status: Response::HTTP_CREATED);
    }

    /**
     * Whether this alta would hand the invitee the administrator role — the one payload shape the extra
     * permission governs. A named predicate rather than an inline `in_array`, because what the guard it
     * serves is about is the *act of delegating administration*, not the mechanics of scanning a list.
     *
     * @param list<Role> $roles
     */
    private function grantsAdmin(array $roles): bool
    {
        return \in_array(Role::ADMIN, $roles, true);
    }

    /**
     * Safe because the payload already refused anything outside the {@see Role} vocabulary with a 422, and
     * refused a body whose `roles` is not a list at all — without that shape check the keys of a JSON object
     * would survive into the variadic below as named arguments, where `email` collides with the invitee.
     *
     * @return list<Role>
     */
    private function rolesFrom(InviteUserRequest $request): array
    {
        return \array_map(Role::from(...), $request->roles);
    }
}
