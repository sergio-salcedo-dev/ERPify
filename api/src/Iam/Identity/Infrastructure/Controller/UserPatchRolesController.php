<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Controller;

use Erpify\Iam\Identity\Application\ChangeUserRoles;
use Erpify\Iam\Identity\Infrastructure\Http\ChangeUserRolesRequest;
use Erpify\Iam\Identity\Infrastructure\Http\UserResourceMapper;
use Erpify\Shared\Access\Domain\Role;
use Erpify\Shared\Http\Infrastructure\Responder\ResourceResponder;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
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
 */
#[Route('/backoffice/users/{id}/roles', name: self::ROUTE_NAME, methods: ['PATCH'])]
#[IsGranted('users.changeRoles')]
final readonly class UserPatchRolesController
{
    public const string ROUTE_NAME = 'backoffice_user_change_roles';

    public function __construct(
        private ChangeUserRoles $changeUserRoles,
        private UserResourceMapper $userResourceMapper,
        private ResourceResponder $resourceResponder,
    ) {
    }

    /**
     * @throws ExceptionInterface when normalization of the detail resource fails
     */
    public function __invoke(
        string $id,
        #[MapRequestPayload]
        ChangeUserRolesRequest $request,
    ): Response {
        $user = $this->changeUserRoles->run($id, ...$this->rolesFrom($request));

        return $this->resourceResponder->respond(
            $this->userResourceMapper->toDetailResource($user),
        );
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
