<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Domain;

/**
 * The bounds of an evidence upload, held in one place because every one of them is derived from another: the
 * deadline from the minimum accepted throughput, the lease from the idle cut the transport spike measured, the
 * staging age-sweep margin from the deadline, the lease and the reaper cadence together. Retuning one without
 * the others is how a sweep comes to delete a staged object a live upload is still writing.
 *
 * Values and their sources (spec `spec-documents-context`, memlog T1-1 to T1-7, owner decisions of 2026-11-06):
 *  - {@see MAX_ORIGINAL_BYTES}: 100 MiB, decided; exactly that many bytes are accepted.
 *  - {@see MIN_THROUGHPUT_BYTES_PER_SECOND}: 256 KiB/s, decided; with {@see DEADLINE_MARGIN_SECONDS} a
 *    100 MiB upload gets 460 s.
 *  - {@see LEASE_SECONDS}: 90 s, above the 60 s idle cut measured on a stalled body read plus a heartbeat gap.
 *  - {@see HEARTBEAT_INTERVAL_SECONDS}: 10 s, checked after each {@see READ_CHUNK_BYTES} read.
 *  - {@see STAGING_ORPHAN_AGE_SECONDS}: 20 min, above deadline (460 s) + lease (90 s) + reaper cadence (60 s).
 */
final class UploadLimits
{
    public const int MAX_ORIGINAL_BYTES = 104_857_600;

    public const int MIN_THROUGHPUT_BYTES_PER_SECOND = 262_144;

    public const int DEADLINE_MARGIN_SECONDS = 60;

    public const int LEASE_SECONDS = 90;

    public const int HEARTBEAT_INTERVAL_SECONDS = 10;

    public const int READ_CHUNK_BYTES = 65_536;

    /** The PHP time limit re-armed at each heartbeat, and the one the commit transaction runs under. */
    public const int STREAM_TIME_LIMIT_SECONDS = 90;

    public const int COMMIT_TIME_LIMIT_SECONDS = 60;

    public const int DECLARED_TTL_SECONDS = 86_400;

    public const int COMMITTED_RETENTION_SECONDS = 604_800;

    public const int STAGING_ORPHAN_AGE_SECONDS = 1_200;

    /** How old a `Received` row with `promoted_at` NULL must be before the roll-forward sweep takes it. */
    public const int ROLL_FORWARD_AGE_SECONDS = 120;

    public const int MAX_STREAMING_PER_ACTOR = 2;

    public const int MAX_STREAMING_GLOBAL = 4;

    public const int MAX_LIVE_DECLARED_PER_ACTOR = 10;

    private function __construct()
    {
    }

    /**
     * Seconds between the claim and the upload deadline: the declared size at the minimum accepted throughput,
     * rounded up, plus the fixed margin.
     */
    public static function deadlineSecondsFor(int $declaredBytes): int
    {
        return \intdiv(
            $declaredBytes + self::MIN_THROUGHPUT_BYTES_PER_SECOND - 1,
            self::MIN_THROUGHPUT_BYTES_PER_SECOND,
        )
            + self::DEADLINE_MARGIN_SECONDS;
    }
}
