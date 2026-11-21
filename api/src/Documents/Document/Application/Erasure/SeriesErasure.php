<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application\Erasure;

/**
 * What one run of a series erasure did and what it left: the versions this call erased, newest first, and the
 * versions of the series still not `Erased` when it finished, read back from the database rather than assumed. The
 * series is closed before the run lists it, so a run that returns leaves `remaining` empty; a run that fails throws
 * instead and is resumed by repeating it.
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
