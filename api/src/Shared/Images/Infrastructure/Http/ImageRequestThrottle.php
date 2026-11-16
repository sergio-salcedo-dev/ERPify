<?php

declare(strict_types=1);

namespace Erpify\Shared\Images\Infrastructure\Http;

use Erpify\Shared\Audit\Application\ActorContextFactory;
use Erpify\Shared\ErrorContract\Domain\Exception\RateLimitExceeded;
use Erpify\Shared\ErrorContract\Infrastructure\Http\RateLimitSnapshot;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * The per-identity-and-route budgets of the image surface, spent at the controller edge before the body is read or
 * the image is looked up: one for the write routes, one for `GET /api/v1/images/{imageId}`. The IP-keyed
 * `anonymous_api` bucket stays in front of both and bounds a source; these bound a session, and they key on the
 * ROUTE so one consumer's writes never spend another's.
 *
 * The key is an HMAC under `kernel.secret` of the identity and the route name, for the reason
 * {@see \Erpify\Iam\Invitation\Infrastructure\Security\InvitationResendThrottle} gives: the limiter stores its key in
 * a cache pool no erasure path reaches, so a person's id never sits there in clear. An identity the actor context
 * cannot name (not a user session) falls back to the client address, which is the only thing the request offers.
 * A refusal stamps the snapshot so the response carries `Retry-After`.
 */
final readonly class ImageRequestThrottle
{
    private const string WRITE = 'images.write';

    private const string READ = 'images.read';

    public function __construct(
        #[Autowire(service: 'limiter.image_write_per_identity_route')]
        private RateLimiterFactoryInterface $writeLimiter,
        #[Autowire(service: 'limiter.image_read_per_identity_route')]
        private RateLimiterFactoryInterface $readLimiter,
        private ActorContextFactory $actorContext,
        private RequestStack $requestStack,
        #[Autowire(param: 'kernel.secret')]
        private string $secret,
    ) {
    }

    /**
     * The limiter key of one identity's budget on one route: an HMAC of the case-folded identity and the route name,
     * under the budget's own prefix so the write and read buckets of one identity and route never collide.
     */
    public static function keyFor(string $budget, string $identity, string $route, string $secret): string
    {
        return \hash_hmac('sha256', $budget . ':' . $route . ':' . \strtolower($identity), $secret);
    }

    /**
     * @throws RateLimitExceeded when the identity's write budget on this route is spent for the window
     */
    public function ensureWithinWriteBudget(): void
    {
        $this->spend($this->writeLimiter, self::WRITE);
    }

    /**
     * @throws RateLimitExceeded when the identity's read budget on this route is spent for the window
     */
    public function ensureWithinReadBudget(): void
    {
        $this->spend($this->readLimiter, self::READ);
    }

    private function spend(RateLimiterFactoryInterface $limiter, string $budget): void
    {
        $request = $this->requestStack->getCurrentRequest();
        $key = self::keyFor($budget, $this->identity($request), $this->route($request), $this->secret);
        $snapshot = RateLimitSnapshot::of($limiter->create($key)->consume(), $key);

        if ($snapshot->accepted) {
            return;
        }

        if ($request instanceof Request) {
            $snapshot->stampOn($request);
        }

        throw $snapshot->refusal();
    }

    private function identity(?Request $request): string
    {
        $actorId = $this->actorContext->current()->actorId;

        return $actorId ?? 'ip:' . ($request?->getClientIp() ?? 'unknown');
    }

    private function route(?Request $request): string
    {
        $route = $request?->attributes->get('_route');

        return \is_string($route) ? $route : 'unrouted';
    }
}
