<?php

declare(strict_types=1);

namespace Erpify\Iam\Session\Infrastructure\Controller;

use Erpify\Iam\Session\Application\CurrentSessionReference;
use Erpify\Iam\Session\Application\RevokeSession;
use Erpify\Iam\Session\Domain\SessionId;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `POST /sessions/revoke-current` — "sign out this device". Revokes the signed-in user's current registry
 * session AND invalidates the native session so the cookie is dropped: without this, "log out" leaves an ACTIVE,
 * still-admissible session behind the cookie for the whole TTL (a resumable session on a shared machine). After
 * this, the very cookie that carried the request is inert. Reading the correlation before invalidating, revoking
 * (idempotent — a no-op if already gone) then clearing the native session; always 204 — a logout must not fail.
 */
#[Route('/sessions/revoke-current', name: 'iam_revoke_current_session', methods: ['POST'])]
final readonly class RevokeCurrentSessionController
{
    public function __construct(
        private CurrentSessionReference $currentSession,
        private RevokeSession $revokeSession,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $currentSessionId = $this->currentSession->get();

        if ($currentSessionId instanceof SessionId) {
            $this->revokeSession->revoke($currentSessionId);
        }

        $request->getSession()->invalidate();

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
