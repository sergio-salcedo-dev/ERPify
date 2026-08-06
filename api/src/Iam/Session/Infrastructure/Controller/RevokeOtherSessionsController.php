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
 *
 * It carries NO rate-limit budget, and that absence is deliberate. Every other credential-adjacent surface is
 * budgeted by an identifier an adversary can hold or guess — the identity behind a stolen session, or an email
 * address that is not a secret — so an adversary can *spend* those surfaces and leave their owner unable to
 * act. This endpoint is the one that must survive that: it is how the legitimate owner ejects an intruder who
 * holds a session, and the intruder gains nothing by draining it, because eviction is symmetric while
 * RE-ENTRY IS NOT — the owner returns with the credential, whereas a revoked session cannot be re-minted
 * without one. A budget here would hand the adversary a way to close the last door out. The abuse it would
 * otherwise guard against is empty: the caller can only revoke rows already keyed to their own identity, and
 * each call is idempotent once the rows are gone.
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
