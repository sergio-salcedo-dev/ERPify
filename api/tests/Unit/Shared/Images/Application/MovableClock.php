<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Images\Application;

use DateTimeImmutable;
use Erpify\Shared\Clock\Domain\Clock;
use Override;

/** A clock the test moves, so the 60-second period can be crossed without the suite waiting for it. */
final class MovableClock implements Clock
{
    private DateTimeImmutable $now;

    public function __construct()
    {
        $this->now = new DateTimeImmutable('2026-08-31T10:00:00+00:00');
    }

    #[Override]
    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(int $seconds): void
    {
        $this->now = $this->now->modify(\sprintf('+%d seconds', $seconds));
    }
}
