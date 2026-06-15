<?php

declare(strict_types=1);

namespace Erpify\Shared\Infrastructure\Clock;

use DateTimeImmutable;
use Erpify\Shared\Domain\Clock\Clock;
use Override;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Production {@see Clock} adapter delegating to the Symfony Clock service, so the whole application
 * shares one configurable time source that a `MockClock` can freeze in tests.
 */
#[AsAlias(Clock::class)]
final readonly class SymfonyClock implements Clock
{
    public function __construct(private ClockInterface $clock)
    {
    }

    #[Override]
    public function now(): DateTimeImmutable
    {
        return $this->clock->now();
    }
}
