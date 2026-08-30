<?php

declare(strict_types=1);

namespace Erpify\Iam\Session\Infrastructure\Controller;

use Erpify\Iam\Session\Application\RevokeOtherSessions;
use Erpify\Iam\Session\Infrastructure\Security\AdmittedSessionProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `POST /sessions/revoke-others` — "sign out my other devices". Revokes every active session of the signed-in
 * user except the one in hand, which is never self-expelled. The subject is the current session's `userId`
 * (already validated by the gate); returns 204. Resolving that row, and refusing the request when there is
 * none, belongs to {@see AdmittedSessionProvider}.
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
        private AdmittedSessionProvider $admittedSession,
        private RevokeOtherSessions $revokeOtherSessions,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $current = $this->admittedSession->requireAdmitted($request);

        $this->revokeOtherSessions->revoke($current->userId(), $current->id);

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
