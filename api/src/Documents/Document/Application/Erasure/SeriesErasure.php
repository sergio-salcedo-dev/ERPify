<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application\Erasure;

/**
 * What one run of a series erasure did and what it left: the versions this call erased, newest first, and the
 * versions of the series still not `Erased` when it finished. `remaining` is not empty when a version was filed while
 * the run worked, under a predecessor that was still `Ready`; the operator runs it again.
 */
final readonly class SeriesErasure
{
    /**
     * @param list<string> $erased
     * @param list<string> $remaining
     */
    public function __construct(
        public string $seriesId,
        public array $erased,
        public array $remaining,
    ) {
    }
}
