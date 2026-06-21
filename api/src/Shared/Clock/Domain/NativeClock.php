<?php

declare(strict_types=1);

namespace Erpify\Shared\Clock\Domain;

use DateTimeImmutable;
use Override;

/**
 * Default {@see Clock} reading the host wall clock. {@see SystemClock} falls back to it when no
 * other clock is set, so production keeps real time while tests can freeze it.
 */
final class NativeClock implements Clock
{
    #[Override]
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
}
