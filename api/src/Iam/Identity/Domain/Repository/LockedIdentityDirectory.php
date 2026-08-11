<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Domain\Repository;

use DateTimeImmutable;

/**
 * Consumer-owned port answering the one question the lockout-notification sweep asks of this context: which
 * identities are under a lock right now?
 *
 * Narrow on purpose, mirroring {@see LiveIdentityDirectory} and {@see ActiveAdministratorDirectory}. The
 * alternative was a list method on {@see UserRepository}, whose contract is aggregate lifecycle — and taking
 * it would hydrate a whole {@see \Erpify\Iam\Identity\Domain\Entity\User} per row of the table merely to find
 * the candidates. The overwhelmingly common tick finds nothing locked at all, and this port makes that tick
 * cost one statement and zero hydrations.
 *
 * **It answers CANDIDACY, never the notification policy.** Whether a given identity is owed a notice — the
 * suppression window, the lifecycle status, the lock still holding at the instant of the decision — is
 * decided once, by {@see \Erpify\Iam\Identity\Domain\Entity\User::awaitsLockoutNoticeAt()}. Restating any of
 * it here would put one rule in two places, and the drift would be invisible in the direction that matters: a
 * `WHERE` clause that quietly stops matching removes rows the aggregate is then never asked about, so every
 * unit test over the aggregate stays green while the control silently sends nothing.
 *
 * Ids and nothing else cross THIS boundary. That is a statement about the port, not about the tick: the
 * sweep resolves each id through {@see UserRepository} moments later, so the worker does hold the address and
 * the credential digest of every identity it notifies. What the narrow port bounds is the exposure of the
 * identities it merely *considers* — which, on the common empty tick, is all of them.
 */
interface LockedIdentityDirectory
{
    /**
     * Ids of identities whose lockout expiry is still in the future at `$now`.
     *
     * Bounded by construction rather than by a limit clause: a lock lives
     * {@see \Erpify\Iam\Identity\Domain\Entity\User::LOCK_DURATION}, so the set is whoever tripped one in the
     * last fifteen minutes. Selecting on a non-null `lockedUntil` instead would grow without bound — an
     * account locked out and never signed into again keeps its expiry forever, because
     * {@see \Erpify\Iam\Identity\Domain\Entity\User::clearLockout()} only runs on a successful login.
     *
     * @return list<string> ordered by id, so a sweep's work is deterministic across ticks
     */
    public function findLockedAt(DateTimeImmutable $now): array;
}
