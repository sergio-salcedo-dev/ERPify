<?php

declare(strict_types=1);

namespace Erpify\Iam\Session\Infrastructure\Controller;

use Erpify\Iam\Session\Application\CurrentSessionReference;
use Erpify\Iam\Session\Application\RevokeSession;
use Erpify\Iam\Session\Domain\SessionId;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

/**
 * `POST /sessions/revoke-current` — "sign out this device". Revokes the signed-in user's current registry
 * session AND invalidates the native session so the cookie is dropped: without this, "log out" leaves an ACTIVE,
 * still-admissible session behind the cookie for the whole TTL (a resumable session on a shared machine). After
 * this, the very cookie that carried the request is inert.
 *
 * The revoke is best-effort: if it throws (a store outage), the native session is still invalidated and the
 * response is still 204 — dropping the cookie wins over a durable revoke, since a logout that returns 5xx would
 * leave the ex-user's cookie live and the registry row ACTIVE (resumable once the store recovers). The failed
 * revoke is logged; the left-behind ACTIVE row is correlation-orphaned and self-cleans via the TTL / retention
 * job. Always 204 — a logout must not fail.
 */
#[Route('/sessions/revoke-current', name: 'iam_revoke_current_session', methods: ['POST'])]
final readonly class RevokeCurrentSessionController
{
    public function __construct(
        private CurrentSessionReference $currentSession,
        private RevokeSession $revokeSession,
        #[Autowire(service: 'monolog.logger.observability')]
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $currentSessionId = $this->currentSession->get();

        if ($currentSessionId instanceof SessionId) {
            $this->revokeCurrentSession($currentSessionId);
        }

        $request->getSession()->invalidate();

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    private function revokeCurrentSession(SessionId $currentSessionId): void
    {
        try {
            $this->revokeSession->revoke($currentSessionId);
        } catch (Throwable $throwable) {
            $this->logger->warning('Sign-out could not revoke the current session; the cookie is dropped anyway.', [
                'exception' => $throwable,
            ]);
        }
    }
}
