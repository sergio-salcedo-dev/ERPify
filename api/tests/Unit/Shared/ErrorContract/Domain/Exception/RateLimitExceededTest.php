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
