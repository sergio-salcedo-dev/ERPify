<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application\Resource;

/**
 * Wire contract of a series erasure: the versions this call erased, newest first, and the versions still not `Erased`
 * when it finished. A non-empty `remaining` means a version was filed meanwhile; the operator runs it again.
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
