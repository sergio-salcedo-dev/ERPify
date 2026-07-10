<?php

declare(strict_types=1);

namespace Erpify\Iam\Session\Infrastructure\Controller;

use Erpify\Iam\Session\Application\CurrentSessionReference;
use Erpify\Iam\Session\Application\RevokeOtherSessions;
use Erpify\Iam\Session\Domain\Entity\Session;
use Erpify\Iam\Session\Domain\Exception\SessionNoLongerActive;
use Erpify\Iam\Session\Domain\Repository\SessionRepository;
use Erpify\Iam\Session\Domain\SessionId;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `POST /sessions/revoke-others` — "sign out my other devices". Revokes every active session of the signed-in
 * user except the one in hand, which is never self-expelled. The subject is the current session's `userId`
 * (already validated by the gate); returns 204.
 */
#[Route('/sessions/revoke-others', name: 'iam_revoke_other_sessions', methods: ['POST'])]
final readonly class RevokeOtherSessionsController
{
    public function __construct(
        private CurrentSessionReference $currentSession,
        private SessionRepository $sessions,
        private RevokeOtherSessions $revokeOtherSessions,
    ) {
    }

    public function __invoke(): Response
    {
        $currentSessionId = $this->currentSession->get();

        if (!$currentSessionId instanceof SessionId) {
            throw SessionNoLongerActive::forRequest();
        }

        $current = $this->sessions->findActiveById($currentSessionId);

        if (!$current instanceof Session) {
            throw SessionNoLongerActive::forRequest();
        }

        $this->revokeOtherSessions->revoke($current->userId(), $currentSessionId);

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
