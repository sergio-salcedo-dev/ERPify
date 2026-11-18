<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application\Erasure;

/** Overrides still set: their count and the age of the oldest, by the database clock. Carries no reason or reference. */
final readonly class StandingOverride
{
    public function __construct(
        public int $count,
        public int $oldestAgeSeconds,
    ) {
    }
}
