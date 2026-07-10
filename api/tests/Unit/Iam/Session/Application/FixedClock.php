<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Session\Application;

use DateTimeImmutable;
use Erpify\Shared\Clock\Domain\Clock;
use Override;

/**
 * {@see Clock} frozen at a preset instant, so a minting/revocation use case computes a deterministic expiry and
 * event timestamp. Kept local to the Session module's tests.
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
