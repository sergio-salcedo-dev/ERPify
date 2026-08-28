<?php

declare(strict_types=1);

namespace Erpify\Shared\Images\Domain\Storage;

/**
 * How a storage failure is to be treated, as a closed vocabulary disjoint from the pipeline's own
 * {@see \Erpify\Shared\Images\Domain\Exception\FailureCategory}.
 *
 * They are kept apart because they answer different questions about different subjects. A pipeline
 * category is a verdict on the BYTES a caller supplied — `decode_failure` tells that caller something
 * about its own input. A storage category is a verdict on the SUBSTRATE, and says nothing about the
 * bytes at all. Folding them into one enum would give a single dimension two meanings and let each
 * consumer `match` over cases that can never arise for it. The string values stay disjoint, so the
 * `failure_category` dimension remains one closed universe by union.
 */
enum StorageFailureCategory: string
{
    /**
     * The object is DEMONSTRABLY absent. Only a check that could have seen the object had it been there
     * may answer this: a failure to determine existence is never absence.
     */
    case ConfirmedAbsence = 'storage_confirmed_absence';

    /** The substrate failed in a way a retry can plausibly resolve. */
    case Transient = 'storage_transient_failure';

    /**
     * The substrate failed in a way no retry resolves — no space, a root that is not there, a path that
     * cannot be traversed. Collapsing these into transient trains a consumer to retry for ever against
     * something only an operator can fix.
     */
    case Permanent = 'storage_permanent_failure';
}
