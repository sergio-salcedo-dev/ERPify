<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Images;

use DateTimeImmutable;
use Erpify\Shared\Clock\Domain\Clock;
use Override;

/**
 * {@see Clock} frozen at a preset instant, so a stamped `createdAt` is a value the test chose rather than
 * whatever the wall clock read while the row was written.
 *
 * A named class rather than an anonymous one, and not only for reuse: PDepend cannot parse a `readonly`
 * anonymous class, so a file containing one is skipped whole by PHPMD — the analyser reports one error and
 * every rule it would have applied to that file silently does not run.
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
