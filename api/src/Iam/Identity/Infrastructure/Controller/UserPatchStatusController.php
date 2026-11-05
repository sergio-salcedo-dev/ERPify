<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Controller;

use Erpify\Iam\Identity\Application\ChangeUserStatus;
use Erpify\Iam\Identity\Application\ProveActorPassword;
use Erpify\Iam\Identity\Domain\Enum\IdentityStatus;
use Erpify\Iam\Identity\Domain\HashedPassword;
use Erpify\Iam\Identity\Infrastructure\Http\ChangeUserStatusRequest;
use Erpify\Iam\Identity\Infrastructure\Http\UserResourceMapper;
use Erpify\Iam\Identity\Infrastructure\Security\CurrentPasswordProofThrottle;
use Erpify\Iam\Identity\Infrastructure\Security\PasswordHasher;
use Erpify\Iam\Identity\Infrastructure\Security\SecurityUser;
use Erpify\Shared\Http\Infrastructure\Responder\ResourceResponder;
use Erpify\Shared\Http\Infrastructure\StrictRequestPayload;
use LogicException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\Exception\ExceptionInterface;

/**
 * Post-active lifecycle transition surface, gated by `users.changeStatus` — a resource is governed, not a
 * route, the same string-literal pattern as the read controllers. Mounts under `/api/v1`, resolving to
 * `/api/v1/backoffice/users/{id}/status`. The controller only translates the requested target to the
 * aggregate's intent method; the "keep ≥1 active ADMIN" guard, the `Uuid::ensure`/`UserNotFound` edge and the
 * transactional publish all live in {@see ChangeUserStatus}, and every error is carried by the RFC 9457
 * pipeline — never a manual body.
 *
 * **Every transition re-proves the CALLER's current password**, whoever the target is: a stolen administrator
 * session could otherwise suspend or deactivate the real owner, and a suspended owner's next redemption is
 * refused, so evicting the session does not give the access back. It is unconditional for the reason
 * {@see UserPatchRolesController} states, spends the one per-identity budget the credential routes share
 * ({@see CurrentPasswordProofThrottle}), and the password travels in the body. See {@see ProveActorPassword}.
 *
 * Its object coupling sits at the threshold, and deliberately stays there: the proof's three collaborators are
 * named in this body because `make php.lint.credential-proof` reads the calls as tokens inside the bound action,
 * so folding them behind one facade would buy the metric and blind the gate.
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[Route('/backoffice/users/{id}/status', name: self::ROUTE_NAME, methods: ['PATCH'])]
#[IsGranted('users.changeStatus')]
final readonly class UserPatchStatusController
{
    public const string ROUTE_NAME = 'backoffice_user_change_status';

    public function __construct(
        private ChangeUserStatus $changeUserStatus,
        private ProveActorPassword $proveActorPassword,
        private PasswordHasher $passwordHasher,
        private CurrentPasswordProofThrottle $currentPasswordProofThrottle,
        private UserResourceMapper $userResourceMapper,
        private ResourceResponder $resourceResponder,
    ) {
    }

    /**
     * @throws ExceptionInterface when normalization of the detail resource fails
     */
    public function __invoke(
        #[CurrentUser]
        SecurityUser $actor,
        string $id,
        #[StrictRequestPayload]
        ChangeUserStatusRequest $request,
    ): Response {
        $actorId = $actor->id() ?? throw new LogicException('An authenticated identity must have an id.');

        $this->currentPasswordProofThrottle->ensureWithinBudget($actorId);

        $this->proveActorPassword->ensure(
            $actorId,
            fn (HashedPassword $stored): bool => $this->passwordHasher->verify(
                $request->currentPassword,
                $stored->toString(),
            ),
        );

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
