<?php

declare(strict_types=1);

namespace Erpify\Iam\Session\Application;

use DateInterval;
use Erpify\Iam\Session\Domain\Repository\SessionRepository;
use Erpify\Shared\Clock\Domain\Clock;

/**
 * The registry's retention floor: drops the rows that no longer answer any question, so `ip` and `device` —
 * operational PII the aggregate deliberately keeps out of the five-year audit trail — stop outliving the short
 * life that justifies storing them in clear. Nothing else bounds that life: revocation is logical and keeps
 * the row, and expiry is a predicate applied at read time, so without this sweep the only path that removes a
 * session row is a GDPR erasure of its subject.
 *
 * The published seam both entries come through — the `iam:session:prune` command an operator runs, and the
 * daily maintenance tick — so the two windows are written once and cannot drift between them.
 *
 * **NOT the erasure path.** A GDPR erasure drops a subject's rows immediately through {@see PurgeUserSessions},
 * inside the erasure transaction, and does not wait for a window. This sweep is the routine floor under every
 * other row and knows nothing about subjects; conflating the two would put a person's erasure behind a delay.
 *
 * **The two windows measure different things, which is why they are two.** A revoked row is dead the instant
 * it was revoked, and its remaining value is operational — long enough that "this device was signed out on
 * the 3rd" is still answerable while someone investigates a compromise. An unrevoked row only lapsed, so its
 * clock starts at its absolute expiry rather than at a decision anyone made; with the seven-day TTL of
 * {@see StartSession} that puts it about 97 days past the login that minted it. A row goes when the first of
 * its applicable windows elapses, so 97 days is the ceiling rather than a figure a later revocation can push
 * out — see {@see SessionRepository::deleteRetired()}.
 */
final readonly class PruneRetiredSessions
{
    private const string REVOKED_RETENTION = 'P30D';

    private const string EXPIRED_RETENTION = 'P90D';

    public function __construct(
        private SessionRepository $sessions,
        private Clock $clock,
    ) {
    }

    /**
     * @return int the number of rows removed
     */
    public function prune(): int
    {
        $now = $this->clock->now();

        return $this->sessions->deleteRetired(
            $now->sub(new DateInterval(self::REVOKED_RETENTION)),
            $now->sub(new DateInterval(self::EXPIRED_RETENTION)),
        );
    }
}
