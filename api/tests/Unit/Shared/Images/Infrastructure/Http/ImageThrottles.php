<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Images\Infrastructure\Http;

use Erpify\Shared\Audit\Domain\ActorContext;
use Erpify\Shared\Images\Infrastructure\Http\ImageRequestThrottle;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\FixedActorContextFactory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * A real {@see ImageRequestThrottle} over in-memory limiter storage, so a controller test spends the real budget
 * instead of a mock that would agree with whatever the test expected.
 *
 * @internal
 */
final class ImageThrottles
{
    public const string SECRET = 'a-test-secret';

    public const string ACTOR_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a90';

    /**
     * A budget no test of another subject will exhaust.
     */
    public static function permissive(string $route = 'shared_image_get'): ImageRequestThrottle
    {
        return self::withBudgets(1_000, 1_000, $route);
    }

    public static function withBudgets(
        int $writeLimit,
        int $readLimit,
        string $route = 'shared_image_get',
        ?string $actorId = self::ACTOR_ID,
        ?Request $request = null,
        ?InMemoryStorage $storage = null,
    ): ImageRequestThrottle {
        $storage ??= new InMemoryStorage();
        $actor = null === $actorId ? ActorContext::anonymous() : ActorContext::forUser($actorId);
        $request ??= new Request();
        $request->attributes->set('_route', $route);
        $requests = new RequestStack();
        $requests->push($request);

        return new ImageRequestThrottle(
            self::limiter('image_write_per_identity_route', $writeLimit, $storage),
            self::limiter('image_read_per_identity_route', $readLimit, $storage),
            new FixedActorContextFactory($actor),
            $requests,
            self::SECRET,
        );
    }

    private static function limiter(string $id, int $limit, InMemoryStorage $storage): RateLimiterFactory
    {
        return new RateLimiterFactory(
            ['id' => $id, 'policy' => 'sliding_window', 'limit' => $limit, 'interval' => '1 minute'],
            $storage,
        );
    }
}
