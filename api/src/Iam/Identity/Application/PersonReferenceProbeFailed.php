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

    /**
     * The wired collection could not be assembled, so the control does not even know which places it was
     * meant to check. Named after the wiring and not a place because at that point there is no place to
     * name: `!tagged_iterator` builds each source as the loop reaches it, so a source whose dependencies
     * cannot be resolved surfaces here rather than from any read.
     */
    public static function collectingSources(Throwable $cause): self
    {
        return new self(
            'Could not assemble the person-reference sources, so no verdict was reached.',
            0,
            $cause,
        );
    }

    /**
     * A source could not name the axis it covers. Its class is reported and not the axis, because the axis
     * is precisely what failed to exist — and the class is what an operator greps for.
     */
    public static function namingAxisOf(string $sourceClass, Throwable $cause): self
    {
        return new self(
            \sprintf(
                'The person-reference source "%s" could not name its axis, so no verdict was reached.',
                $sourceClass,
            ),
            0,
            $cause,
        );
    }
}
