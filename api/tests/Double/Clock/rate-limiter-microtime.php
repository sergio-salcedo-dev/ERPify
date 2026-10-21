<?php

declare(strict_types=1);

// Every namespace of symfony/rate-limiter that reads `microtime()` unqualified. The reasons it is a function
// shim rather than an injected clock, and why it must load before any limiter runs, are on RateLimiterClock.

namespace Symfony\Component\RateLimiter {
    use Erpify\Tests\Double\Clock\RateLimiterClock;

    /**
     * @SuppressWarnings("PHPMD.BooleanArgumentFlag") The signature is PHP's own; the shim cannot choose another.
     */
    function microtime(bool $asFloat = false): float|string
    {
        return RateLimiterClock::microtime($asFloat);
    }
}

namespace Symfony\Component\RateLimiter\Policy {
    use Erpify\Tests\Double\Clock\RateLimiterClock;

    /**
     * @SuppressWarnings("PHPMD.BooleanArgumentFlag") The signature is PHP's own; the shim cannot choose another.
     */
    function microtime(bool $asFloat = false): float|string
    {
        return RateLimiterClock::microtime($asFloat);
    }
}

namespace Symfony\Component\RateLimiter\Storage {
    use Erpify\Tests\Double\Clock\RateLimiterClock;

    /**
     * @SuppressWarnings("PHPMD.BooleanArgumentFlag") The signature is PHP's own; the shim cannot choose another.
     */
    function microtime(bool $asFloat = false): float|string
    {
        return RateLimiterClock::microtime($asFloat);
    }
}
