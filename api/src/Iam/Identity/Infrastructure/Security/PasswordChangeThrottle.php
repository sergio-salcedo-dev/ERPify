<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Security;

use Erpify\Shared\ErrorContract\Domain\Exception\RateLimitExceeded;
use Erpify\Shared\ErrorContract\Infrastructure\Http\RateLimitSnapshot;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Per-identity budget over `POST /me/password`, consumed at the controller edge where the other per-target
 * throttles live.
 *
 * It REFUSES OUT LOUD, which is the deliberate divergence from {@see PasswordRecoveryThrottle}. That one owes
 * the caller neutrality because its surface is pre-identity: a per-target 429 there answers "this account
 * exists" to someone who was only guessing. This endpoint is reachable only through an established session, so
 * the caller already holds the identity the budget is keyed on — there is no existence left to disclose, and
 * silently accepting a request the server refuses to act on would be the worse contract.
 *
 * The refusal reuses {@see RateLimitExceeded} (the `RateLimited` marker → 429 `rate-limited`), so the existing
 * Problem Details pipeline renders it and no new marker joins the error contract. It also stamps its
 * {@see RateLimitSnapshot} on the request, which is what makes the 429 obey the same header contract as the
 * per-IP one — without it the response would carry no `Retry-After` and a `RateLimit-Remaining` counted off
 * the per-IP budget, which this request left almost untouched.
 *
 * The identity id is passed as the exception's `limiterKey`, and that placement is the point: the property is
 * neither serialised into the response body (the constructor's `context` carries only the three numbers) nor
 * part of the fixed field set the per-error log line emits. It stays inside the exception object. Promoting it
 * to either sink would put a person's id somewhere no erasure path reaches — the same reason
 * {@see \Erpify\Iam\Identity\Infrastructure\Controller\ChangeMyPasswordController} keeps the identity out of
 * its `critical` context and leans on the correlation id instead.
 */
final readonly class PasswordChangeThrottle
{
    public function __construct(
        #[Autowire(service: 'limiter.password_change_per_identity')]
        private RateLimiterFactoryInterface $perIdentityLimiter,
        private RequestStack $requestStack,
    ) {
    }

    /**
     * @throws RateLimitExceeded when this identity has spent its budget for the current window
     */
    public function ensureWithinBudget(string $identityId): void
    {
        $snapshot = RateLimitSnapshot::of($this->perIdentityLimiter->create($identityId)->consume(), $identityId);

        if ($snapshot->accepted) {
            return;
        }

        $request = $this->requestStack->getCurrentRequest();

        // Only the refusal is stamped. An accepted request leaves the per-IP snapshot in place, so the
        // `RateLimit-*` family keeps one meaning across the API; on the rejected path the headers must
        // describe the budget that actually said no, or the 429 ships without `Retry-After` and with a
        // `RateLimit-Remaining` counted from a bucket this request never drained.
        if ($request instanceof Request) {
            $snapshot->stampOn($request);
        }

        throw $snapshot->refusal();
    }
}
