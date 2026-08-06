<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\ErrorContract\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\RateLimitExceeded;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @internal
 */
#[CoversClass(RateLimitExceeded::class)]
final class RateLimitExceededTest extends TestCase
{
    public function testExposesProblemDetailsTypeAndTitleConstants(): void
    {
        $rateLimitExceeded = new RateLimitExceeded(
            retryAfterSeconds: 30,
            limit: 100,
            remaining: 0,
            limiterKey: 'x',
        );

        $this->assertSame(RateLimitExceeded::TYPE, $rateLimitExceeded->type());
        $this->assertSame(RateLimitExceeded::TITLE, $rateLimitExceeded->title());
        $this->assertSame('rate-limited', $rateLimitExceeded->type());
        $this->assertSame('Rate limit exceeded.', $rateLimitExceeded->title());
    }

    public function testContextCarriesQuotaSnapshot(): void
    {
        $rateLimitExceeded = new RateLimitExceeded(
            retryAfterSeconds: 30,
            limit: 100,
            remaining: 0,
            limiterKey: 'principal-id',
        );

        $context = $rateLimitExceeded->context();

        $this->assertSame(30, $context['retryAfterSeconds'] ?? null);
        $this->assertSame(100, $context['limit'] ?? null);
        $this->assertSame(0, $context['remaining'] ?? null);
    }

    /**
     * `context()` is promoted to Problem Details extensions, so whatever it holds travels in the response body
     * and the per-error log line. On the per-identity budget the key is the caller's identity id, and neither
     * sink is reached by any erasure path — so the property may be read in process and never serialised. The
     * assertion exists because the map is one line away from carrying it, and a docblock does not go red.
     */
    public function testTheLimiterKeyIsNeverPromotedToTheSerialisedContext(): void
    {
        $rateLimitExceeded = new RateLimitExceeded(
            retryAfterSeconds: 30,
            limit: 100,
            remaining: 0,
            limiterKey: '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b',
        );

        $context = $rateLimitExceeded->context();

        $this->assertArrayNotHasKey('limiterKey', $context);
        $this->assertNotContains('0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b', $context);
        $this->assertSame('0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b', $rateLimitExceeded->limiterKey);
    }

    public function testPreservesPreviousThrowable(): void
    {
        $previous = new RuntimeException('upstream limiter failure');
        $rateLimitExceeded = new RateLimitExceeded(
            retryAfterSeconds: 1,
            limit: 1,
            remaining: 0,
            limiterKey: 'x',
            previous: $previous,
        );

        $this->assertSame($previous, $rateLimitExceeded->getPrevious());
    }
}
