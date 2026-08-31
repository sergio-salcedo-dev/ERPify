<?php

declare(strict_types=1);

namespace Erpify\Shared\Images\Domain\Read;

use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;
use Erpify\Shared\ErrorContract\Domain\Exception\ServiceUnavailable;
use Throwable;

/**
 * The substrate failed in a way a retry can plausibly resolve, translated for the wire.
 *
 * It exists for the same mechanical reason as its sibling: a marker is only read off a
 * {@see DomainException}, and every class in the tree carrying {@see ServiceUnavailable} extends one.
 * Marking `ImageStorageUnavailable` — a `RuntimeException` — would have produced 500 while looking like it
 * produced 503.
 *
 * **A permanent storage failure deliberately has no counterpart here.** `ENOSPC`, a missing root or an
 * untraversable directory are not fixed by retrying. Those propagate untranslated and answer 500
 * `unhandled-exception` through the same pipeline, which is where a substrate fault belongs — visible to
 * the error reporter rather than dressed as a transient hiccup.
 *
 * **What the 503 says, stated exactly, because the shorter phrasing was not true.** It signals a
 * transient unavailability for which a client MAY retry; this API publishes no retry delay. No
 * `ServiceUnavailable` response in this deployment carries `Retry-After` — the header is emitted in one
 * place in the whole API, on the rate limiter's 429 — and calling the status "an instruction to retry"
 * described a contract nothing implements. Nor is the absence an oversight to close in passing: a fixed
 * delay is syntactically valid and operationally false without a duration this deployment can predict,
 * and the marker is shared, so stamping one here would change the wire contract of every other exception
 * carrying it. Publishing a delay is a decision about the `ServiceUnavailable` contract as a whole and
 * belongs to whoever has an availability target to derive it from.
 */
final class ImageTemporarilyUnavailable extends DomainException implements ServiceUnavailable
{
    public static function fromStorageFailure(Throwable $previous): self
    {
        return new self(
            type: '',
            title: 'The requested image is temporarily unavailable.',
            context: [],
            previous: $previous,
        );
    }
}
