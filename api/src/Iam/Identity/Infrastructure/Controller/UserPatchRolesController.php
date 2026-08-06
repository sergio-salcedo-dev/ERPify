<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Controller;

use Erpify\Iam\Identity\Application\ChangeUserRoles;
use Erpify\Iam\Identity\Infrastructure\Http\ChangeUserRolesRequest;
use Erpify\Iam\Identity\Infrastructure\Http\UserResourceMapper;
use Erpify\Shared\Access\Domain\Role;
use Erpify\Shared\Http\Infrastructure\Responder\ResourceResponder;
use Erpify\Shared\Http\Infrastructure\StrictRequestPayload;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\Exception\ExceptionInterface;

/**
 * Role-assignment surface, gated by `users.changeRoles` — a resource is governed, not a route, the same
 * string-literal pattern as the read controllers. Mounts under `/api/v1`, resolving to
 * `/api/v1/backoffice/users/{id}/roles`. The payload is the identity's complete resulting set, so a client
 * never has to reason about which roles it is adding or removing.
 *
 * The controller only turns the validated wire strings into the domain vocabulary; the "keep ≥1 active ADMIN"
 * guard, the redundant-set no-op, the `Uuid::ensure`/`UserNotFound` edge and the transactional publish all live
 * in {@see ChangeUserRoles}, and every error is carried by the RFC 9457 pipeline — never a manual body.
 *
 * A second, narrower check rides on the payload rather than the route: promoting somebody to administrator is a
 * distinct act from adjusting a member's roles, so a set carrying `ADMIN` additionally demands
 * `users.grantAdmin`. It is imperative because it is conditional — `#[IsGranted]` would refuse every role
 * change, including the ones the actor is entitled to make. It stays here rather than in {@see ChangeUserRoles}
 * for the same reason the route gate does: the use case is also driven with no session behind it.
 */
#[Route('/backoffice/users/{id}/roles', name: self::ROUTE_NAME, methods: ['PATCH'])]
#[IsGranted('users.changeRoles')]
final readonly class UserPatchRolesController
{
    public const string ROUTE_NAME = 'backoffice_user_change_roles';

    private const string GRANT_ADMIN_PERMISSION = 'users.grantAdmin';

    public function __construct(
        private ChangeUserRoles $changeUserRoles,
        private UserResourceMapper $userResourceMapper,
        private ResourceResponder $resourceResponder,
        private AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    /**
     * @throws ExceptionInterface when normalization of the detail resource fails
     */
    public function __invoke(
        string $id,
        #[StrictRequestPayload(acceptFormat: ['json'])]
        ChangeUserRolesRequest $request,
    ): Response {
        $roles = $this->rolesFrom($request);

        if ($this->grantsAdmin($roles) && !$this->authorizationChecker->isGranted(self::GRANT_ADMIN_PERMISSION)) {
            // The message travels as the RFC 9457 `title`, so it names the refused act and no internals.
            throw new AccessDeniedException('You may not grant the ADMIN role.');
        }

        $user = $this->changeUserRoles->run($id, ...$roles);

        return $this->resourceResponder->respond(
            $this->userResourceMapper->toDetailResource($user),
        );
    }

    /**
     * Whether the resulting set would leave the target holding the administrator role — the one payload shape
     * the extra permission governs. A named predicate rather than an inline `in_array`, because what the guard
     * it serves is about is the *act of delegating administration*, not the mechanics of scanning a list.
     *
     * Deliberately a property of the *requested* set alone, never a delta against what the target already
     * holds: re-sending `ADMIN` to somebody who has it is still an assertion that they should have it, and a
     * caller who may not make that assertion must not be able to launder it through a redundant PATCH.
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
     * would survive into the variadic below as named arguments.
     *
     * @return list<Role>
     */
    private function rolesFrom(ChangeUserRolesRequest $request): array
    {
        return \array_map(Role::from(...), $request->roles);
    }
}
