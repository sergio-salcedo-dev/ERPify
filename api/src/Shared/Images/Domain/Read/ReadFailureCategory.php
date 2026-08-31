<?php

declare(strict_types=1);

namespace Erpify\Shared\Images\Domain\Read;

/**
 * How a read failure that is NOT a verdict on the substrate is reported, as a third closed vocabulary
 * beside {@see \Erpify\Shared\Images\Domain\Storage\StorageFailureCategory} and the pipeline's
 * {@see \Erpify\Shared\Images\Domain\Exception\FailureCategory}.
 *
 * It is a third enum rather than two more cases on the storage one because the subject differs. A storage
 * category answers "is the substrate healthy"; its own docblock says so and a test iterates its cases
 * demanding a producing class from the storage port for each. These two answer "is the object this row
 * promises servable", a question the adapter cannot even ask: it never sees `Image::digest()` and never
 * sees the serving budget. Folding them in would give one dimension two subjects and force a fourth
 * storage exception to exist for a condition storage did not detect.
 *
 * The string values stay disjoint from both siblings, so `failure_category` remains one closed universe by
 * union — which is the property that lets a single log query see confirmed absence, transient failure,
 * permanent failure and these two without knowing how many enums produce them.
 */
enum ReadFailureCategory: string
{
    /**
     * The bytes came back and are not the bytes the row attests. Never served: a representation that does
     * not hash to its own digest is not the canonical representation of anything.
     */
    case DigestMismatch = 'read_digest_mismatch';

    /**
     * The row declares a byte size above what this deployment will materialise in one process. Refused
     * BEFORE the read, because the failure it avoids — memory exhaustion — is a fatal error rather than a
     * `Throwable`, so nothing downstream would turn it into a response at all.
     */
    case ObjectTooLarge = 'read_object_too_large';
}
