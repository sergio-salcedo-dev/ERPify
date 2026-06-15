<?php

declare(strict_types=1);

namespace Erpify\Shared\Domain\Clock;

use DateTimeImmutable;

/**
 * Ambient access to the current instant for domain code no container can inject into — aggregates
 * and domain events. It reads through the {@see Clock} port (defaulting to the host wall clock via
 * {@see NativeClock}), so a test freezes the whole domain's notion of "now" with a single
 * {@see set()} instead of threading an instant through every constructor and factory.
 *
 * Application and infrastructure services that CAN receive a {@see Clock} from the container must
 * inject it directly; this ambient accessor exists only for the layers dependency injection cannot
 * reach.
 */
final class SystemClock
{
    private static ?Clock $clock = null;

    public static function set(Clock $clock): void
    {
        self::$clock = $clock;
    }

    public static function reset(): void
    {
        self::$clock = null;
    }

    public static function now(): DateTimeImmutable
    {
        return (self::$clock ??= new NativeClock())->now();
    }
}
