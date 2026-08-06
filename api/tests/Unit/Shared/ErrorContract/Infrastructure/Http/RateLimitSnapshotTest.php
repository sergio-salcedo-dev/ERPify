<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\ErrorContract\Infrastructure\Http;

use Erpify\Shared\ErrorContract\Infrastructure\Http\RateLimitSnapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * @internal
 */
#[CoversClass(RateLimitSnapshot::class)]
final class RateLimitSnapshotTest extends TestCase
{
    private const string LIMITER_KEY = 'a-key';

    public function testAnAdmittedDecisionCarriesTheRemainingBudget(): void
    {
        $snapshot = RateLimitSnapshot::of($this->limiter(limit: 3)->consume(), self::LIMITER_KEY);

        $this->assertTrue($snapshot->accepted);
        $this->assertSame(3, $snapshot->limit);
        $this->assertSame(2, $snapshot->remaining);
        $this->assertSame(self::LIMITER_KEY, $snapshot->limiterKey);
    }

    public function testARefusalNeverReportsANegativeRemainderNorAZeroRetry(): void
    {
        $limiter = $this->limiter(limit: 1);
        $limiter->consume();
        $limiter->consume();

        // Symfony reports a negative remainder once the window is over-consumed, and a retry instant that can
        // round to the current second; neither may reach a header.
        $snapshot = RateLimitSnapshot::of($limiter->consume(), self::LIMITER_KEY);

        $this->assertFalse($snapshot->accepted);
        $this->assertSame(0, $snapshot->remaining);
        $this->assertGreaterThanOrEqual(1, $snapshot->retryAfterSeconds);
    }

    public function testItRoundTripsThroughTheRequestItIsStampedOn(): void
    {
        $request = Request::create('/api/v1/anything');
        $snapshot = RateLimitSnapshot::of($this->limiter(limit: 2)->consume(), self::LIMITER_KEY);

        $snapshot->stampOn($request);

        $this->assertSame($snapshot, RateLimitSnapshot::readFrom($request));
    }

    public function testAnUnstampedRequestReadsBackAsNoSnapshot(): void
    {
        $unstamped = RateLimitSnapshot::readFrom(Request::create('/api/v1/anything'));

        $this->assertNotInstanceOf(RateLimitSnapshot::class, $unstamped);
    }

    /**
     * The typed read is also the shape check: a foreign listener storing something else under the same key
     * must read back as "no snapshot" rather than reaching the header code as a malformed value.
     */
    public function testAForeignValueUnderTheSameKeyIsNotASnapshot(): void
    {
        $request = Request::create('/api/v1/anything');
        $request->attributes->set(RateLimitSnapshot::ATTRIBUTE_KEY, ['limit' => 5, 'remaining' => 4]);

        $this->assertNotInstanceOf(RateLimitSnapshot::class, RateLimitSnapshot::readFrom($request));
    }

    public function testTheRefusalItDescribesCarriesItsOwnNumbers(): void
    {
        $limiter = $this->limiter(limit: 1);
        $limiter->consume();

        $snapshot = RateLimitSnapshot::of($limiter->consume(), self::LIMITER_KEY);

        $refusal = $snapshot->refusal();

        $this->assertSame($snapshot->limit, $refusal->limit);
        $this->assertSame($snapshot->remaining, $refusal->remaining);
        $this->assertSame($snapshot->retryAfterSeconds, $refusal->retryAfterSeconds);
        $this->assertSame(self::LIMITER_KEY, $refusal->limiterKey);
    }

    private function limiter(int $limit): LimiterInterface
    {
        return (new RateLimiterFactory(
            ['id' => 'snapshot_test', 'policy' => 'sliding_window', 'limit' => $limit, 'interval' => '1 minute'],
            new InMemoryStorage(),
        ))->create(self::LIMITER_KEY);
    }
}
