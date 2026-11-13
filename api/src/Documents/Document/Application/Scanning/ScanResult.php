<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application\Scanning;

/**
 * How one attempt to scan one document ended, for the sweep's accounting and for tests.
 */
enum ScanResult
{
    /** The claim was lost to another worker or the row was no longer due. */
    case NotClaimed;
    case Ready;
    case Quarantined;
    /** A deterministic failure: the document is in `ScanFailed` and will not be retried. */
    case Failed;
    /** No verdict: the claim went back to `Received` (and into `ScanFailed` at a cap). */
    case Returned;
    /** The scan stopped counting nothing: a superseded claim, an erasure, a verdict the clock refused. */
    case Aborted;
}
