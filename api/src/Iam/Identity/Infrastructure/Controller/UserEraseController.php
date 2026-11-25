<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Controller;

use Erpify\Iam\Identity\Application\FulfilIdentityErasure;
use Erpify\Iam\Identity\Application\FulfilIdentityErasureResult;
use Erpify\Iam\Identity\Application\ProveActorPassword;
use Erpify\Iam\Identity\Domain\Exception\UserNotFound;
use Erpify\Iam\Identity\Domain\HashedPassword;
use Erpify\Iam\Identity\Infrastructure\Http\EraseIdentityRequest;
use Erpify\Iam\Identity\Infrastructure\Security\CurrentPasswordProofThrottle;
use Erpify\Iam\Identity\Infrastructure\Security\PasswordHasher;
use Erpify\Iam\Identity\Infrastructure\Security\SecurityUser;
use Erpify\Shared\Http\Infrastructure\StrictRequestPayload;
use LogicException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * GDPR "right to erasure" surface, gated by `users.erase` — a resource is governed, not a route, the same
 * string-literal pattern as the read and status controllers. Mounts under `/api/v1`, resolving to
 * `POST /api/v1/backoffice/users/{id}/erase`: a verb-and-path of its own, like the document erasure, because the
 * proof of the caller's password travels in a body and the shared HTTP client sends none with a `DELETE`. The
 * hard-delete leaves nothing to serialize, so a successful erasure is a `204 No Content`.
 *
 * **Every erasure re-proves the CALLER's current password.** It is irreversible and it destroys another person's
 * identity and trail; a stolen administrator session could otherwise erase any member, and evicting that session
 * does not bring one back. The proof spends the one per-identity budget the credential routes share
 * ({@see CurrentPasswordProofThrottle}), after the permission check so a refused caller spends nothing, and it
 * runs BEFORE the id is validated or looked up: a malformed id, a self-erasure and an absent identity all answer
 * only to a caller who has proved the password, so the proof is never an existence oracle. It lives here and not
 * in {@see FulfilIdentityErasure} because the console drives that use case with no session behind it
 * (`identity:gdpr:erase-subject`); see {@see ProveActorPassword}.
 *
 * The controller is thin: the chained erasure — every link {@see FulfilIdentityErasureResult::ERASED_CATEGORIES}
 * names, in one transaction — the `Uuid::ensure` edge (→ 400 `invalid-uuid`), the ≥1-active-admin guard (→ 409)
 * and the self-erasure refusal (→ 409) all live in {@see FulfilIdentityErasure}. The only surface-specific
 * decision here is the absent id: the service reports `identityErased = false` for a well-formed id that resolved
 * to no live identity, which the console — always operating on a listed row — treats as a 404, carried by the
 * RFC 9457 pipeline (never a manual body).
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[Route('/backoffice/users/{id}/erase', name: self::ROUTE_NAME, methods: ['POST'])]
#[IsGranted('users.erase')]
final readonly class UserEraseController
{
    public const string ROUTE_NAME = 'backoffice_user_erase';

    public function __construct(
        private FulfilIdentityErasure $fulfilIdentityErasure,
        private ProveActorPassword $proveActorPassword,
        private PasswordHasher $passwordHasher,
        private CurrentPasswordProofThrottle $currentPasswordProofThrottle,
    ) {
    }

    public function __invoke(
        #[CurrentUser]
        SecurityUser $actor,
        string $id,
        #[StrictRequestPayload]
        EraseIdentityRequest $request,
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

        // A well-formed id with no live identity is a 404 for the console (which only ever erases a listed row).
        // The service still runs idempotently first, so a residual trail left behind by an identity-only delete
        // gets anonymised (and self-audited) before this 404 — a completed cleanup, not a no-op. Do not gate the
        // erase on this branch: that would leave such orphaned rows un-anonymised over HTTP. The one link that
        // does not participate in that cleanup is the business log, and the service gates it internally: its
        // predicate matches by value across every kind of aggregate, so it is the only one an id denoting no
        // person could reach.
        if (!$this->fulfilIdentityErasure->execute($id)->identityErased) {
            throw UserNotFound::withId($id);
        }

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
