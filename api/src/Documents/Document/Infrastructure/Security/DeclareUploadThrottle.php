<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Security;

use Erpify\Shared\ErrorContract\Domain\Exception\RateLimitExceeded;
use Erpify\Shared\ErrorContract\Infrastructure\Http\RateLimitSnapshot;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * The per-actor budget on the declare step, spent at the controller edge before the use case runs. Each declare
 * creates a row and reserves a slot of the actor's live-intent cap, so a caller looping on it is bounded by
 * time as well as by the cap.
 *
 * The key is an HMAC under `kernel.secret` of the case-folded actor id, for the reason
 * {@see \Erpify\Iam\Invitation\Infrastructure\Security\InvitationResendThrottle} gives: the limiter stores its key
 * in a cache pool no erasure path reaches. A refusal stamps the snapshot so the response carries `Retry-After`.
 */
final readonly class DeclareUploadThrottle
{
    public function __construct(
        #[Autowire(service: 'limiter.document_upload_declare_per_actor')]
        private RateLimiterFactoryInterface $perActorLimiter,
        private RequestStack $requestStack,
        #[Autowire(param: 'kernel.secret')]
        private string $secret,
    ) {
    }

    /**
     * The limiter key of an actor's declare budget: an HMAC of the case-folded id, so the cache pool never holds
     * the id itself.
     */
    public static function keyFor(string $actorId, string $secret): string
    {
        return \hash_hmac('sha256', 'documents.upload.declare:' . \strtolower($actorId), $secret);
    }

    /**
     * @throws RateLimitExceeded when the actor's declare budget is spent for the window
     */
    public function ensureWithinBudget(string $actorId): void
    {
        $key = self::keyFor($actorId, $this->secret);
        $snapshot = RateLimitSnapshot::of($this->perActorLimiter->create($key)->consume(), $key);

        if ($snapshot->accepted) {
            return;
        }

        $request = $this->requestStack->getCurrentRequest();

        if ($request instanceof Request) {
            $snapshot->stampOn($request);
        }

        throw $snapshot->refusal();
    }
}
