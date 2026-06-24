<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double;

use DateTimeImmutable;
use Erpify\Shared\Clock\Domain\Clock;
use Override;

/**
 * {@see Clock} frozen at a preset instant, so the entry's `occurredOn` is deterministic.
 *
 * @internal
 */
final readonly class FixedClock implements Clock
{
    public function __construct(private DateTimeImmutable $now)
    {
    }

    #[Override]
    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}
