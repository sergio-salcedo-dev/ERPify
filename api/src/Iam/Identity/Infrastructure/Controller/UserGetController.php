<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Controller;

use Erpify\Iam\Identity\Application\UserFinder;
use Erpify\Iam\Identity\Infrastructure\Http\UserResourceMapper;
use Erpify\Shared\Http\Infrastructure\Responder\ResourceResponder;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Read-only single-identity detail surface, gated by `users.read` — the same permission governs the list;
 * a resource is governed, not a route. Mounts under `/api/v1`, resolving to `/api/v1/backoffice/users/{id}`.
 * A malformed id is a 400 `invalid-uuid` and an absent one a 404, both raised inside {@see UserFinder} and
 * carried by the RFC 9457 pipeline — never a manual error body.
 */
#[Route('/backoffice/users/{id}', name: self::ROUTE_NAME, methods: ['GET'])]
#[IsGranted('users.read')]
final readonly class UserGetController
{
    public const string ROUTE_NAME = 'backoffice_user_get';

    public function __construct(
        private UserFinder $userFinder,
        private UserResourceMapper $userResourceMapper,
        private ResourceResponder $resourceResponder,
    ) {
    }

    public function __invoke(string $id): Response
    {
        return $this->resourceResponder->respond(
            $this->userResourceMapper->toDetailResource($this->userFinder->find($id)),
        );
    }
}
