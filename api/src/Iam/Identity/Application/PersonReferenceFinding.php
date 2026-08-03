<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application;

use Erpify\Shared\Privacy\Domain\PersonReferenceAxis;

/**
 * One axis of {@see UnreconciledPersonReferences} that has something to report: the place, and the subject
 * ids found there that no live identity backs.
 *
 * A pair rather than an entry in a label-keyed map, because two axes can derive the same label — a short
 * class name is unique per module, not per tree — and a report where one axis silently overwrites another is
 * precisely the merged-verdict defect this control exists to avoid, moved from the reconciler into its
 * output.
 */
final readonly class PersonReferenceFinding
{
    /**
     * @param list<string> $subjectIds
     */
    public function __construct(
        public PersonReferenceAxis $axis,
        public array $subjectIds,
    ) {
    }
}
