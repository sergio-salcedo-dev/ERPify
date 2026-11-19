<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Domain\Erasure;

/**
 * The provisional bounds of the erasure, each with the argument that fixes it. They are constants rather than
 * configuration: the completion sweep's margin is DERIVED from the others, so a value an environment could move
 * independently would let the sweep steal an erasure whose transaction is still running.
 */
final class ErasureLimits
{
    /** How long the synchronous ledger write may take before its outcome is called unknown. */
    public const int LEDGER_WRITE_TIMEOUT_SECONDS = 10;

    /** How long the erasure transaction may wait for a row lock (`lock_timeout`). */
    public const int LOCK_TIMEOUT_MILLISECONDS = 5_000;

    /** How long any one statement of the erasure transaction may run (`statement_timeout`); also the sweep's bound. */
    public const int STATEMENT_TIMEOUT_MILLISECONDS = 10_000;

    /** The row locks the erasure transaction waits on, one after the other: the intent, the document, the keystore. */
    public const int TRANSACTION_LOCKS = 3;

    /**
     * The statements the erasure transaction runs, counted from `ApplyErasure` (the time bound, the document read, the
     * three locks, the compare-and-set, the custody append's lock, number and insert, the destruction, the marker
     * reopen) and rounded up: each may run for the statement bound.
     */
    public const int TRANSACTION_STATEMENTS = 10;

    /**
     * Slack on top of the bounds above. restic stamps an entry's time on the WRITING host, which is also the host that
     * judges its age, so what this covers is clock drift between the two moments (NTP steps) and a worker that is slow
     * rather than dead.
     */
    public const int CLOCK_SKEW_MARGIN_SECONDS = 60;

    /**
     * The longest the erasure transaction can run: every lock may wait its bound and every statement may run its bound.
     */
    public const int TRANSACTION_BOUND_SECONDS = self::TRANSACTION_LOCKS * self::LOCK_TIMEOUT_MILLISECONDS / 1_000
        + self::TRANSACTION_STATEMENTS * self::STATEMENT_TIMEOUT_MILLISECONDS / 1_000;

    /**
     * An entry younger than this is left alone: the request that wrote it may still be inside its transaction, and
     * finishing it from the sweep would record the sweep's reason where the operator's authorisation belongs.
     */
    public const int COMPLETION_MIN_AGE_SECONDS = self::LEDGER_WRITE_TIMEOUT_SECONDS
        + self::TRANSACTION_BOUND_SECONDS
        + self::CLOCK_SKEW_MARGIN_SECONDS;

    /** How long a sealed object may outlive its row's `Erased` custody entry before the alarm names it. */
    public const int OBJECT_ALARM_SECONDS = 3_600;

    /**
     * How long an erased document's derivatives may stay unconfirmed released after its `Erased` custody entry before
     * the alarm names it: twice the async transport's retry horizon (about three hours), so a deletion request still
     * being retried never trips it (ADR `images-vs-documents-conservation-contract.md` D8).
     */
    public const int DERIVATIVE_ALARM_SECONDS = 21_600;

    /**
     * How long the release sweep waits before asking again for a document whose derivatives it already queued: past
     * the async transport's retry horizon (about three hours), so a request still being retried is not duplicated, and
     * a request lost to a dead letter is issued again before the alarm bound. It bounds what a deletion that keeps
     * failing writes to `event_store` and the queue to one request per image every four hours.
     */
    public const int DERIVATIVE_REREQUEST_SECONDS = 14_400;

    private function __construct()
    {
    }
}
