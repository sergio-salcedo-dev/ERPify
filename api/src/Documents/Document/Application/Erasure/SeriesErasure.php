<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application\Erasure;

/**
 * What one run of a series erasure did and what it left: the versions this call erased, newest first, and the
 * versions of the series still not `Erased` when it finished. The series is closed before the run lists anything, so
 * nothing is filed meanwhile and `remaining` is empty on a normal return (bar a concurrent run of the same series that
 * reopened it); it is reported so the caller can check, and a failure never returns a partial result — it
 * propagates, and a repeat continues.
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
