<?php

declare(strict_types=1);

namespace Erpify\Iam\Session\Application;

/**
 * Holds, in the caller's transaction, the identity a session is about to be minted for — so the session and the
 * event announcing it land on one side of that identity's erasure or the other.
 *
 * Nothing in the schema references the identity, so a login admitted just before an erasure would otherwise
 * insert its `iam_session` row and append its `SessionStarted` after the erasure has purged the subject's sessions
 * and pseudonymised the business log: a session naming a person who no longer exists, and an event nothing will
 * ever rewrite. Held first, the erasure waits for the login to commit and then purges and rewrites what it wrote.
 * Arriving second, the login finds no identity and mints nothing.
 *
 * The lock is taken before any session row, which is the erasure's own order (identity, then sessions), so the two
 * cannot deadlock.
 *
 * Two strengths, one per caller. A revocation only needs the identity to EXIST — it revokes precisely the
 * identities that stopped being active — so {@see holdsLive()} excludes the erasure and nothing else. A login
 * mints a session that must belong to an identity that is still admitted, so {@see holdsAdmissible()} also
 * excludes a concurrent status change: a suspension committing while a login mints would otherwise run its
 * revoke-everything before the new row exists, and leave a suspended identity holding a live session.
 */
interface SessionSubjectLock
{
    /**
     * Holds the identity's row until the caller's transaction ends and reports whether it still exists.
     *
     * **Must run inside that transaction**: a lock taken outside one is released at the end of its own statement
     * and protects nothing. An implementation refuses rather than complying silently.
     */
    public function holdsLive(string $userId): bool;

    /**
     * Holds the identity's row until the caller's transaction ends, against its erasure AND against any change
     * of its status, and reports whether it still exists and is `ACTIVE`. A status change already in flight is
     * waited for and its outcome is what the answer reflects.
     *
     * **Must run inside that transaction**, for the reason {@see holdsLive()} gives.
     */
    public function holdsAdmissible(string $userId): bool;
}
