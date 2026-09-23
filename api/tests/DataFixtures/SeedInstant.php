<?php

declare(strict_types=1);

namespace Erpify\Tests\DataFixtures;

use DateTimeImmutable;
use Symfony\Component\Clock\Clock;

/**
 * The instant every fixture factory hands its aggregate. It reads Symfony's global clock — the one the
 * container's `clock` service delegates to — so a seeded row and the request that later reads it agree on
 * "now": the wall clock under `make db.load.fixtures` and Behat, the pinned suite instant under PHPUnit.
 * Reading a second source here would let a seeded row and the request that reads it disagree about "now".
 */
final class SeedInstant
{
    public static function now(): DateTimeImmutable
    {
        return Clock::get()->now();
    }
}
