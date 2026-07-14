<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application;

/**
 * The constant-time floor every pre-identity branch pays before answering: one unit of password-hashing work,
 * so an anonymous caller cannot correlate response latency with whether an account exists or what state it is
 * in. Login's not-found/malformed branches, the INVITED admission rejection and every forgot-password outcome
 * all pay this same floor.
 *
 * The floor equalises the DOMINANT term of the timing oracle — the credential KDF (tens of milliseconds) —
 * not every micro-difference: the residual read-vs-write differential of the silent paths is sub-millisecond
 * and drowns in the KDF's own variance. Should that residue ever become measurable, the mitigation is to
 * equalise the store work inside this same envelope, never to defer the write (async would break
 * read-your-writes for the legitimate requester).
 */
interface PreIdentityTimingFloor
{
    public function equalise(): void;
}
