<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use DateTimeImmutable;
use Erpify\Shared\Clock\Domain\Clock;
use Override;

/**
 * {@see Clock} frozen at a preset instant, so a lockout use case computes a deterministic expiry and event
 * timestamp. Kept local to the Identity module's tests so they carry no dependency on another module's helpers.
 *
 * @internal
 */
final readonly class FixedClock implements Clock
{
    public function __construct(private DateTimeImmutable $now)
    {
    }

    /**
     * Named constructor from an ISO-8601 string, so a test that needs no date arithmetic can freeze the
     * clock without referencing {@see DateTimeImmutable} itself.
     */
    public static function at(string $instant): self
    {
        return new self(new DateTimeImmutable($instant));
    }

    #[Override]
    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}
