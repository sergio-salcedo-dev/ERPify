<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application;

use Erpify\Iam\Session\Application\CurrentSessionReference;
use Erpify\Iam\Session\Application\EvictOtherSessions;
use Erpify\Iam\Session\Domain\SessionId;
use LogicException;

/**
 * Evicts every session of the identity except the one THIS request established, and reports whether that one
 * is still alive. The counterpart of {@see RevokeCurrentSessionBestEffort} with the radius inverted: that one
 * undoes this request's session and spares the rest, this one keeps this request's session and ends the rest.
 *
 * It names the survivor the same way its sibling names the victim — through {@see CurrentSessionReference},
 * which the login stashed when it minted the row — so it needs no identifier handed across the login seam.
 *
 * **It is not best-effort, and that is the difference from every other session collaborator in this layer.**
 * A credential change can swallow its teardown because the native `refreshUser` path de-authenticates the old
 * sessions anyway; nothing de-authenticates them after a recovery redemption, which replaces no credential.
 * The eviction is therefore the security property itself, and a failure has to abort what it guards: it runs
 * inside the redemption's consuming transaction and anything it raises rolls the consumption back with it.
 *
 * A missing correlation fails the same way rather than widening into a revoke of every session, which would
 * take the one this request just minted and spend the secret over an owner left with nothing. It is not
 * reachable from a redemption whose login succeeded — the minting listener fails closed first.
 */
final readonly class KeepOnlyCurrentSession
{
    public function __construct(
        private CurrentSessionReference $currentSession,
        private EvictOtherSessions $evictOtherSessions,
    ) {
    }

    /**
     * @throws LogicException when the login left no session correlation to keep
     *
     * @return bool whether this request's session was still active when the identity's sessions were locked
     */
    public function evictOthers(string $userId): bool
    {
        $survivor = $this->currentSession->get();

        if (!$survivor instanceof SessionId) {
            throw new LogicException("No session correlation to keep; refusing to evict the identity's sessions.");
        }

        return $this->evictOtherSessions->evict($userId, $survivor);
    }
}
