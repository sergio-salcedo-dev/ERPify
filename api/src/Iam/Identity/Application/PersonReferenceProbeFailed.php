<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application;

use RuntimeException;
use Throwable;

/**
 * A read that {@see ReconcileErasedSubjectReferences} depends on failed, so the reconciliation reached no
 * verdict. It says nothing about whether a person reference survived its erasure — that is the point.
 *
 * The two outcomes are acted on by different people and only one of them is actionable as compliance. A
 * finding is a missed erasure with a documented repair; this is an infrastructure fault whose repair is to
 * fix the infrastructure and run the control again. The reads that raise it are ordinary:
 * {@see \Erpify\Iam\Identity\Domain\Repository\LiveIdentityDirectory::existingIdsAmong()} declares
 * PostgreSQL's 65535-parameter ceiling as a hard bound it fails at rather than degrades past, and any source
 * can meet a transient driver error.
 *
 * A dedicated type rather than a bare `catch (Throwable)` at each consumer, and the difference is not
 * cosmetic: this use case raises {@see \LogicException} on purpose when two sources claim one axis — a wiring
 * bug that leaves a whole place unreported. A blanket catch would answer "infrastructure blip" to exactly
 * that, and the control would go on looking healthy while it silently stopped covering a table.
 *
 * The message names the place that could not be read and never what it holds. An id reaching a log or an
 * error tracker from here would be the same violation this control exists to detect, committed by the
 * control itself.
 */
final class PersonReferenceProbeFailed extends RuntimeException
{
    public static function reading(string $place, Throwable $cause): self
    {
        return new self(
            \sprintf('Could not read the person references held by "%s", so no verdict was reached.', $place),
            0,
            $cause,
        );
    }
}
