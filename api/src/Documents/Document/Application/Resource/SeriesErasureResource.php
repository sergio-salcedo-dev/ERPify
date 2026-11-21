<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application\Resource;

/**
 * Wire contract of a series erasure: the versions this call erased, newest first, and the versions still not `Erased`
 * when it finished. The series is closed first, so `remaining` is empty on a normal return; a failed run answers an
 * error instead, and the operator repeats it.
 */
final readonly class SeriesErasureResource
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
