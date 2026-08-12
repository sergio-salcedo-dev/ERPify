<?php

declare(strict_types=1);

namespace Erpify\Shared\Persistence\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\Conflict;
use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;
use Throwable;

/**
 * The database refused the write because another row still references the one it touched — a foreign key
 * rejected at flush time, after whatever guard the use case ran had already passed.
 *
 * **Why {@see Conflict} (409) and not
 * {@see \Erpify\Shared\ErrorContract\Domain\Exception\ServiceUnavailable} (503).** The two failures this seam
 * translates are opposites for the caller. A deadlock is nobody's fault and retrying the identical bytes is
 * expected to work, so it is 503. This one is a genuine conflict of state: the request cannot succeed until
 * the referencing rows are gone, and repeating it unchanged will fail identically. 409 is the code that says
 * so, and `Conflict extends ClientError` carries the Sentry decision that fits — a caller racing a delete
 * against an insert is an expected outcome, not an actionable fault.
 *
 * **Why it is generic.** A use case that can name the dependents owes the caller a better answer than this
 * one, and should catch it to say what is still referencing what — the way bank deletion answers with its
 * own account count. This exists so that every OTHER path arrives as a 409 the client can reason about
 * instead of the bare 500 an untranslated DBAL exception produces.
 */
final class ReferentialIntegrityViolation extends DomainException implements Conflict
{
    public function __construct(Throwable $previous)
    {
        parent::__construct(
            type: 'referential-integrity-violation',
            title: 'The operation was refused because other records still reference the data it changed.',
            previous: $previous,
        );
    }
}
