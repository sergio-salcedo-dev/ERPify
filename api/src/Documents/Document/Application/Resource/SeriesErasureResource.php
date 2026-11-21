<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application\Resource;

/**
 * Wire contract of a series erasure: the versions this call erased, newest first, and the versions still not `Erased`
 * when it finished, read back after the run. It is empty whenever the run completes, since the series is closed before
 * it is listed; a failed run answers its 503 instead, and repeating it continues where it stopped.
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
