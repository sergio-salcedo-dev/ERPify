<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application;

use RuntimeException;

/**
 * The cause a redemption hands its retryable 503 when the session it established was revoked by another
 * session of the identity before the consuming transaction took the lock. It never reaches the wire on its
 * own — {@see \Erpify\Shared\Persistence\Domain\Exception\TransientTransactionFailure} carries it as
 * `previous` — and it exists so the HTTP adapter can tell this outcome from a genuine deadlock, which answers
 * the same 503 over a session that is still alive: only here is the device's native session holding a dead
 * token, which the adapter has to drop or the retry the 503 invites meets the gate's 401 first.
 */
final class RedeemedSessionRevokedInFlight extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The recovered session was revoked before the secret could be consumed.');
    }
}
