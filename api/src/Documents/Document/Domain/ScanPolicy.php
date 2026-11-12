<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Domain;

/**
 * The numbers the scan accounting runs on, in one place because each is derived from another: the lease from the
 * heartbeat, clamd's `MaxScanTime` from the lease, the read timeout from the heartbeat, the stuck-document bound
 * from the outage-return ladder. Retuning one without the others is how a live scan comes to be returned to
 * `Received` under its own feet.
 *
 * Fixed by the scanner spike (clamd 1.4.6 with `api/clamav/clamd.conf`, measured):
 *  - {@see MAX_ATTEMPTS}: 3 claims that began streaming and ended with no verdict.
 *  - {@see MAX_OUTAGE_RETURNS}: 10 returns that are the scanner's absence, walking the whole ladder: 1, 5, 15 and
 *    then 60 minutes six times. The tenth return ends the document instead of backing off, so only returns 1-9
 *    wait: 60 + 300 + 900 + 6 × 3600 = 22,860 s, about 6.35 hours before jitter and 7.6 hours at its +20 percent
 *    maximum.
 *  - {@see DEADLINE_SECONDS}: 10 minutes per claim. A 100 MiB INSTREAM took 6.5 s against a local clamd, so the
 *    deadline is two orders of magnitude of room, and clamd's `MaxScanTime` (90 s) is the tighter bound.
 *  - {@see LEASE_SECONDS}: 2 minutes, renewed every {@see HEARTBEAT_SECONDS}; clamd's `MaxScanTime` (90 s) stays
 *    below it. {@see READ_TIMEOUT_SECONDS} stays below the heartbeat so a wait for the verdict is a poll.
 *  - {@see STUCK_BOUND_SECONDS}: 10 hours, above the 7.6 hours the ladder can wait at its jitter maximum.
 */
final class ScanPolicy
{
    public const int MAX_ATTEMPTS = 3;

    public const int MAX_OUTAGE_RETURNS = 10;

    /** The outage-return count at which the report starts naming the document. */
    public const int OUTAGE_ALARM_AT = 5;

    public const int DEADLINE_SECONDS = 600;

    public const int LEASE_SECONDS = 120;

    public const int HEARTBEAT_SECONDS = 30;

    public const int READ_TIMEOUT_SECONDS = 20;

    /** clamd's `MaxScanTime` in `api/clamav/clamd.conf`, in milliseconds; held below the lease by a gate. */
    public const int CLAMD_MAX_SCAN_TIME_MILLISECONDS = 90_000;

    public const int PROBE_TIMEOUT_SECONDS = 5;

    /** Jitter applied to a backoff, as a fraction either way. */
    public const float JITTER = 0.2;

    /** A document in `Received` or `Scanning` this long, measured from its last custody-bearing event, is stuck. */
    public const int STUCK_BOUND_SECONDS = 36_000;

    /** A `Quarantined` or `ScanFailed` document waits for an operator; this is how long before it is reported. */
    public const int AWAITING_OPERATOR_BOUND_SECONDS = 86_400;

    /** Backoff, in seconds, indexed by the count after the increment (the last entry repeats). */
    private const array COUNTED_LADDER = [60, 300];

    private const array OUTAGE_LADDER = [60, 300, 900, 3600];

    private function __construct()
    {
    }

    /**
     * The base backoff ladder in seconds before jitter, indexed by the count after the increment (the last entry
     * repeats): counted returns use the first two entries, outage returns the whole ladder. The SQL of the return
     * reads these, so the delays a row gets are these.
     *
     * @return non-empty-list<int>
     */
    public static function ladder(bool $counted): array
    {
        return $counted ? self::COUNTED_LADDER : self::OUTAGE_LADDER;
    }
}
