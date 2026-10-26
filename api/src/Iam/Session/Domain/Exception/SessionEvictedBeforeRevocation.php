<?php

declare(strict_types=1);

namespace Erpify\Iam\Session\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;
use Erpify\Shared\ErrorContract\Domain\Exception\Unauthenticated;

/**
 * "Sign out my other devices" refused because the session in hand was evicted between its admission and the
 * row lock — the owner signing out an intruder while the intruder asks to sign out the owner. The wire answer
 * is {@see SessionNoLongerActive}'s, `session-expired` 401 with the same title, so the PWA re-routes to sign-in
 * exactly as it does at the gate.
 *
 * A class of its own, and deliberately not a {@see \Erpify\Shared\Monitoring\Domain\RoutineOutcome}: losing
 * that race takes a concurrent eviction, so it is rare, and a stolen session firing the revocation in a loop is
 * the one shape of it worth seeing in error tracking.
 */
final class SessionEvictedBeforeRevocation extends DomainException implements Unauthenticated
{
    public static function forRequest(): self
    {
        return new self(
            type: 'session-expired',
            title: 'Your session is no longer active. Please sign in again.',
        );
    }
}
