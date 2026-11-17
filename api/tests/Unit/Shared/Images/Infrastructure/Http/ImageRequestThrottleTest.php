<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Images\Infrastructure\Http;

use Erpify\Shared\ErrorContract\Domain\Exception\RateLimitExceeded;
use Erpify\Shared\ErrorContract\Infrastructure\Http\RateLimitSnapshot;
use Erpify\Shared\Images\Infrastructure\Http\ImageRequestThrottle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * The per-identity-and-route budgets of the image surface, over real limiters on in-memory storage.
 *
 * @internal
 */
#[CoversClass(ImageRequestThrottle::class)]
final class ImageRequestThrottleTest extends TestCase
{
    #[Test]
    public function aWriteBudgetIsSpentThenRefusedWithARetryAfter(): void
    {
        $throttle = ImageThrottles::withBudgets(2, 1_000, 'a_write_route');

        $throttle->ensureWithinWriteBudget();
        $throttle->ensureWithinWriteBudget();

        try {
            $throttle->ensureWithinWriteBudget();
            $this->fail('the third write is over the budget of two');
        } catch (RateLimitExceeded $rateLimitExceeded) {
            $this->assertGreaterThanOrEqual(1, $rateLimitExceeded->retryAfterSeconds);
        }
    }

    #[Test]
    public function aRefusalStampsTheSnapshotSoTheResponseCarriesRetryAfter(): void
    {
        $request = new Request();
        $throttle = ImageThrottles::withBudgets(1_000, 1, 'a_read_route', request: $request);
        $throttle->ensureWithinReadBudget();
        $this->assertNotInstanceOf(RateLimitSnapshot::class, RateLimitSnapshot::readFrom($request));

        try {
            $throttle->ensureWithinReadBudget();
            $this->fail('the second read is over the budget of one');
        } catch (RateLimitExceeded) {
            // expected
        }

        $snapshot = RateLimitSnapshot::readFrom($request);
        $this->assertInstanceOf(RateLimitSnapshot::class, $snapshot);
        $this->assertFalse($snapshot->accepted);
        $this->assertGreaterThanOrEqual(1, $snapshot->retryAfterSeconds);
    }

    #[Test]
    public function theWriteAndReadBudgetsOfOneIdentityAndRouteAreSeparate(): void
    {
        $throttle = ImageThrottles::withBudgets(1, 1, 'shared_route');

        $throttle->ensureWithinWriteBudget();
        $throttle->ensureWithinReadBudget();

        $this->expectException(RateLimitExceeded::class);
        $throttle->ensureWithinWriteBudget();
    }

    /**
     * Throttles over ONE shared storage, so a key that collapses onto a constant identity or route is a shared bucket
     * and the second spend is refused.
     */
    #[Test]
    public function aBudgetIsPerIdentityAndPerRouteAndNeverOneBucketForAll(): void
    {
        $storage = new InMemoryStorage();
        $other = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a91';

        ImageThrottles::withBudgets(1, 1, 'route_a', ImageThrottles::ACTOR_ID, storage: $storage)
            ->ensureWithinWriteBudget()
        ;

        // Another actor on the same route, and the same actor on another route, each have a budget of their own.
        ImageThrottles::withBudgets(1, 1, 'route_a', $other, storage: $storage)->ensureWithinWriteBudget();
        ImageThrottles::withBudgets(1, 1, 'route_b', ImageThrottles::ACTOR_ID, storage: $storage)
            ->ensureWithinWriteBudget()
        ;

        // The first actor on the first route is spent.
        $this->expectException(RateLimitExceeded::class);
        ImageThrottles::withBudgets(1, 1, 'route_a', ImageThrottles::ACTOR_ID, storage: $storage)
            ->ensureWithinWriteBudget()
        ;
    }

    #[Test]
    public function anIdentityTheActorContextCannotNameIsKeyedOnTheClientAddress(): void
    {
        $storage = new InMemoryStorage();
        $from = static fn (string $ip): Request => new Request(server: ['REMOTE_ADDR' => $ip]);

        ImageThrottles::withBudgets(1, 1, 'route_a', null, $from('192.0.2.1'), $storage)->ensureWithinReadBudget();
        ImageThrottles::withBudgets(1, 1, 'route_a', null, $from('192.0.2.2'), $storage)->ensureWithinReadBudget();

        $this->expectException(RateLimitExceeded::class);
        ImageThrottles::withBudgets(1, 1, 'route_a', null, $from('192.0.2.1'), $storage)->ensureWithinReadBudget();
    }

    #[Test]
    public function theKeyIsAnHmacOfTheIdentityAndTheRouteAndNeverTheIdentityItself(): void
    {
        $actor = ImageThrottles::ACTOR_ID;

        $secret = ImageThrottles::SECRET;
        $key = ImageRequestThrottle::keyFor('images.write', $actor, 'a_route', $secret);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $key);
        $this->assertStringNotContainsString($actor, $key);
        $this->assertSame($key, ImageRequestThrottle::keyFor('images.write', \strtoupper($actor), 'a_route', $secret));
        $this->assertNotSame($key, ImageRequestThrottle::keyFor('images.write', $actor, 'another_route', $secret));
        $this->assertNotSame($key, ImageRequestThrottle::keyFor('images.read', $actor, 'a_route', $secret));
        $this->assertNotSame($key, ImageRequestThrottle::keyFor('images.write', $actor, 'a_route', 'another-secret'));

        $anotherIdentity = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a91';
        $this->assertNotSame($key, ImageRequestThrottle::keyFor('images.write', $anotherIdentity, 'a_route', $secret));
    }
}
