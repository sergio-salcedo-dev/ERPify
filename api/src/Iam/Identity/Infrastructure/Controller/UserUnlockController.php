<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Controller;

use Erpify\Iam\Identity\Application\Resource\AccountUnlockResource;
use Erpify\Iam\Identity\Application\UnlockUserAccount;
use Erpify\Shared\Http\Infrastructure\Responder\ResourceResponder;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\Exception\ExceptionInterface;

/**
 * Administrative account-unlock surface, gated by `users.unlock` — a resource is governed, not a route, the
 * same string-literal pattern as the read and status controllers. Mounts under `/api/v1`, resolving to
 * `POST /api/v1/backoffice/users/{id}/unlock`. POST rather than PATCH: there is no resource representation to
 * submit, only the intent to clear whatever lockout the target currently holds, so the request carries no body.
 *
 * The controller is thin: `Uuid::ensure` (→ 400 `invalid-uuid`), the `UserNotFound` edge (→ 404), the
 * self-unlock refusal (→ 409) and the actor-attributed security audit all live in {@see UnlockUserAccount}.
 * Success is always 200 — {@see AccountUnlockResource::$unlocked} is what tells a genuine recovery apart from
 * a no-op against an identity that was never locked, so the console never has to guess from the status code.
 */
#[Route('/backoffice/users/{id}/unlock', name: self::ROUTE_NAME, methods: ['POST'])]
#[IsGranted('users.unlock')]
final readonly class UserUnlockController
{
    public const string ROUTE_NAME = 'backoffice_user_unlock';

    public function __construct(
        private UnlockUserAccount $unlockUserAccount,
        private ResourceResponder $resourceResponder,
    ) {
    }

    /**
     * @throws ExceptionInterface when normalization of the resource fails
     */
    public function __invoke(string $id): Response
    {
        $result = $this->unlockUserAccount->run($id);

        return $this->resourceResponder->respond(new AccountUnlockResource(
            $result->user->getId() ?? $id,
            $result->user->email(),
            $result->unlocked,
        ));
    }
}
