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
 * **Why the radius is load-bearing rather than tidy.** Revoking every session of the identity here reaches
 * the OWNER's, through an interleaving an attacker can provoke: whoever holds a leaked secret starts a
 * redemption, the owner revokes that secret from their profile, and the redemption's locked pass then finds
 * the row gone and compensates — over an identity whose owner is signed in and looking at it. That owner may
 * be exactly the person this whole channel exists for, an administrator whose `locked_until` is in the
 * future, who would be left with no secret, no session and no login.
 *
 * **Where the session identity comes from.** `Security::login()` returns no identifier, but it is not the
 * source: {@see \Erpify\Iam\Session\Application\StartSession} mints the {@see SessionId} and stashes it
 * through {@see CurrentSessionReference}, which is the same seam `SessionAdmissionGate` reads on every
 * authenticated request. So naming one session needs no widening of any shared signature — it reads the
 * correlation the login already wrote.
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
                // revoke of every session: widening the radius on the one path where nothing is known is
                // exactly where it would reach a session this request never minted.
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
