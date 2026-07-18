<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Controller;

use Erpify\Iam\Identity\Application\ChangeUserStatus;
use Erpify\Iam\Identity\Domain\Enum\IdentityStatus;
use Erpify\Iam\Identity\Infrastructure\Http\ChangeUserStatusRequest;
use Erpify\Iam\Identity\Infrastructure\Http\UserResourceMapper;
use Erpify\Shared\Http\Infrastructure\Responder\ResourceResponder;
use LogicException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\Exception\ExceptionInterface;

/**
 * Post-active lifecycle transition surface, gated by `users.changeStatus` — a resource is governed, not a
 * route, the same string-literal pattern as the read controllers. Mounts under `/api/v1`, resolving to
 * `/api/v1/backoffice/users/{id}/status`. The controller only translates the requested target to the
 * aggregate's intent method; the "keep ≥1 active ADMIN" guard, the `Uuid::ensure`/`UserNotFound` edge and the
 * transactional publish all live in {@see ChangeUserStatus}, and every error is carried by the RFC 9457
 * pipeline — never a manual body.
 */
#[Route('/backoffice/users/{id}/status', name: self::ROUTE_NAME, methods: ['PATCH'])]
#[IsGranted('users.changeStatus')]
final readonly class UserPatchStatusController
{
    public const string ROUTE_NAME = 'backoffice_user_change_status';

    public function __construct(
        private ChangeUserStatus $changeUserStatus,
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
        ChangeUserStatusRequest $request,
    ): Response {
        $user = match ($request->status) {
            IdentityStatus::SUSPENDED => $this->changeUserStatus->suspend($id),
            IdentityStatus::DEACTIVATED => $this->changeUserStatus->deactivate($id),
            // The request's #[Assert\Choice] already refused INVITED/ACTIVE with a 422, so these arms are
            // unreachable from a validated payload; the guard turns a validation regression into a clear
            // failure rather than a silent wrong transition.
            default => throw new LogicException('Change-status target must be SUSPENDED or DEACTIVATED.'),
        };

        return $this->resourceResponder->respond(
            $this->userResourceMapper->toDetailResource($user),
        );
    }
}
