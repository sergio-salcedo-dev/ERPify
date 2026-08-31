<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Security;

use Erpify\Iam\Identity\Infrastructure\Security\CurrentPasswordProofThrottle;
use Erpify\Shared\ErrorContract\Domain\Exception\RateLimitExceeded;
use Erpify\Shared\ErrorContract\Infrastructure\Http\RateLimitSnapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * @internal
 */
#[CoversClass(CurrentPasswordProofThrottle::class)]
final class CurrentPasswordProofThrottleTest extends TestCase
{
    private const string IDENTITY_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b';

    public function testAdmitsAttemptsWhileTheIdentityStillHasBudget(): void
    {
        $throttle = $this->throttle(limit: 2);

        $throttle->ensureWithinBudget(self::IDENTITY_ID);
        $throttle->ensureWithinBudget(self::IDENTITY_ID);

        $this->expectException(RateLimitExceeded::class);
        $throttle->ensureWithinBudget(self::IDENTITY_ID);
    }

    public function testTheRefusalCarriesTheNumbersTheProblemDetailsBodyRenders(): void
    {
        $throttle = $this->throttle(limit: 1);
        $throttle->ensureWithinBudget(self::IDENTITY_ID);

        try {
            $throttle->ensureWithinBudget(self::IDENTITY_ID);
            $this->fail('A spent budget must refuse out loud.');
        } catch (RateLimitExceeded $rateLimitExceeded) {
            $this->assertSame(1, $rateLimitExceeded->limit);
            $this->assertSame(0, $rateLimitExceeded->remaining);
            $this->assertGreaterThanOrEqual(1, $rateLimitExceeded->retryAfterSeconds);
            $this->assertSame(self::IDENTITY_ID, $rateLimitExceeded->limiterKey);
        }
    }

    public function testAnotherIdentityKeepsItsOwnBudget(): void
    {
        $throttle = $this->throttle(limit: 1);
        $throttle->ensureWithinBudget(self::IDENTITY_ID);

        // No exception: the second identity is a separate bucket, so one caller cannot deny another.
        $throttle->ensureWithinBudget('0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5c');

        $this->expectException(RateLimitExceeded::class);
        $throttle->ensureWithinBudget(self::IDENTITY_ID);
    }

    /**
     * Without this the 429 ships with no `Retry-After` and a `RateLimit-Remaining` counted off the per-IP
     * budget the request barely touched — the response listener renders whatever snapshot the request carries.
     */
    public function testTheRefusalStampsItsOwnBudgetForTheResponseHeaders(): void
    {
        $request = Request::create('/api/v1/me/password', Request::METHOD_POST);
        $requestStack = new RequestStack([$request]);

        $throttle = $this->throttle(limit: 1, requestStack: $requestStack);
        $throttle->ensureWithinBudget(self::IDENTITY_ID);

        $this->assertNotInstanceOf(
            RateLimitSnapshot::class,
            RateLimitSnapshot::readFrom($request),
            'An admitted attempt must leave the per-IP snapshot alone.',
        );

        try {
            $throttle->ensureWithinBudget(self::IDENTITY_ID);
        } catch (RateLimitExceeded) {
            // Expected — the snapshot is stamped before the throw, so the response listener still reads it.
        }

        $snapshot = RateLimitSnapshot::readFrom($request);
        $this->assertInstanceOf(RateLimitSnapshot::class, $snapshot);
        $this->assertFalse($snapshot->accepted);
        $this->assertSame(1, $snapshot->limit);
        $this->assertSame(0, $snapshot->remaining);
        $this->assertSame(self::IDENTITY_ID, $snapshot->limiterKey);
    }

    /**
     * The console has no request. Refusing must still work rather than fataling on a null request.
     */
    public function testItStillRefusesWhenThereIsNoRequestToStamp(): void
    {
        $throttle = $this->throttle(limit: 1, requestStack: new RequestStack());
        $throttle->ensureWithinBudget(self::IDENTITY_ID);

        $this->expectException(RateLimitExceeded::class);
        $throttle->ensureWithinBudget(self::IDENTITY_ID);
    }

    private function throttle(int $limit, ?RequestStack $requestStack = null): CurrentPasswordProofThrottle
    {
        $factory = new RateLimiterFactory(
            ['id' => 'per_identity', 'policy' => 'sliding_window', 'limit' => $limit, 'interval' => '15 minutes'],
            new InMemoryStorage(),
        );

        return new CurrentPasswordProofThrottle($factory, $requestStack ?? new RequestStack());
    }
}
