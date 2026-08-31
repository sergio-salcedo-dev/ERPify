<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application;

use Erpify\Iam\Session\Application\CurrentSessionReference;
use Erpify\Iam\Session\Application\RevokeSession;
use Erpify\Iam\Session\Domain\SessionId;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Undoes the authentication THIS request produced, and nothing else.
 *
 * The compensating counterpart of {@see RevokeSessionsBestEffort}, and the distinction between them is the
 * whole reason this exists. That one is a teardown: a credential just changed, so every session of the
 * identity is stale by definition and revoking all of them is the point. This one is a rollback: a request
 * minted a session and then failed to do the thing the session was minted for, so what has to be undone is
 * that one session — the identity's other sessions were never part of the operation and predate it.
 *
 * **Why the narrow radius is load-bearing rather than tidy.** The coarse form was measured to revoke the
 * OWNER's session in a reachable interleaving: whoever holds a leaked secret starts a redemption, the owner
 * revokes that secret from their profile, and the redemption's locked pass then finds the row gone and
 * compensates — taking down every session of the identity, including the one the owner is looking at. The
 * owner may be exactly the person this whole channel exists for, an administrator whose `locked_until` is in
 * the future: they would have just destroyed their recovery secret and lost their session, leaving no
 * credential, no session and no login.
 *
 * **The session identity is available, which the flow's first design assumed it was not.** `Security::login()`
 * returns no identifier, but it is not the source: {@see \Erpify\Iam\Session\Application\StartSession} mints
 * the {@see SessionId} and stashes it through {@see CurrentSessionReference}, which is the same seam
 * `SessionAdmissionGate` reads on every authenticated request. So the precise revoke needs no widening of any
 * shared signature — it reads the correlation the login already wrote.
 *
 * Best-effort, for the reason its sibling is: this runs while a refusal is being raised, and a session store
 * outage may not turn that refusal into a 500 over work that already happened.
 *
 * The subject id is deliberately absent from the log context, matching {@see RevokeSessionsBestEffort}: this
 * report goes to the always-on `observability` channel, a sink no erasure path reaches. The request's
 * correlation id already ties the entry to its caller.
 */
final readonly class RevokeCurrentSessionBestEffort
{
    public function __construct(
        private CurrentSessionReference $currentSession,
        private RevokeSession $revokeSession,
        private LoggerInterface $logger,
    ) {
    }

    public function revoke(): void
    {
        try {
            $sessionId = $this->currentSession->get();

            if (!$sessionId instanceof SessionId) {
                // No correlation to undo. Not reachable from a redemption whose login succeeded — the minting
                // listener fails closed and its throw aborts the flow long before any compensation — so this
                // is reported rather than silently treated as success, and deliberately NOT widened into a
                // revoke of every session: falling back to the coarse radius here would reinstate the defect
                // this class exists to remove, on the one path where nothing is known.
                $this->logger->warning('Redemption compensation found no session correlation to revoke.');

                return;
            }

            $this->revokeSession->revoke($sessionId);
        } catch (Throwable $throwable) {
            $this->logger->warning(
                'Redemption refused; the session it minted could not be revoked.',
                ['exception' => $throwable],
            );
        }
    }
}
