<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Controller;

use Erpify\Iam\Identity\Application\FulfilIdentityErasure;
use Erpify\Iam\Identity\Domain\Exception\UserNotFound;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * GDPR "right to erasure" surface, gated by `users.erase` — a resource is governed, not a route, the same
 * string-literal pattern as the read and status controllers. Mounts under `/api/v1`, resolving to
 * `DELETE /api/v1/backoffice/users/{id}`. The hard-delete leaves nothing to serialize, so a successful erasure
 * is a `204 No Content`.
 *
 * The controller is thin: the chained erasure (identity + audit-trail anonymisation + business-log
 * anonymisation + session, membership and invitation purge, atomic),
 * the `Uuid::ensure` edge (→ 400 `invalid-uuid`), the ≥1-active-admin guard (→ 409) and the self-erasure
 * refusal (→ 409) all live in {@see FulfilIdentityErasure}. The only surface-specific decision here is the
 * absent id: the service reports `identityErased = false` for a well-formed id that resolved to no live
 * identity, which the console — always operating on a listed row — treats as a 404, carried by the RFC 9457
 * pipeline (never a manual body).
 */
#[Route('/backoffice/users/{id}', name: self::ROUTE_NAME, methods: ['DELETE'])]
#[IsGranted('users.erase')]
final readonly class UserEraseController
{
    public const string ROUTE_NAME = 'backoffice_user_erase';

    public function __construct(
        private FulfilIdentityErasure $fulfilIdentityErasure,
    ) {
    }

    public function __invoke(string $id): Response
    {
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
