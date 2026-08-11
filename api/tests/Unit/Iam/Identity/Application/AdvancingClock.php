<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use DateTimeImmutable;
use Erpify\Shared\Clock\Domain\Clock;
use Override;

/**
 * A {@see Clock} that moves one second forward on every read and counts how many times it was read.
 *
 * Both properties exist because {@see FixedClock} cannot falsify "the sweep takes `now` once per execution":
 * a frozen clock returns the same instant however many times it is asked, so a sweep reading it per row
 * produces byte-identical output and the defect is invisible. Here the reads are both counted and
 * distinguishable, so a per-row read shows up as a count above one and as stamps that disagree.
 *
 * @internal
 */
final class AdvancingClock implements Clock
{
    public int $calls = 0;

    private DateTimeImmutable $next;

    public function __construct(private readonly DateTimeImmutable $start)
    {
        $this->next = $start;
    }

    public static function at(string $instant): self
    {
        return new self(new DateTimeImmutable($instant));
    }

    /** The instant the first read returns, so a test can assert against it without reading the clock. */
    public function firstInstant(): DateTimeImmutable
    {
        return $this->start;
    }

    #[Override]
    public function now(): DateTimeImmutable
    {
        ++$this->calls;

        $now = $this->next;
        $this->next = $now->modify('+1 second');

        return $now;
    }
}
