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
 * The per-identity budget on **re-proving the current password from a live session**, consumed at the
 * controller edge where the other per-target throttles live. Three routes demand that proof — `POST
 * /me/password`, `POST /me/recovery-secret` and `POST /me/recovery-secret/revoke` — and they share ONE
 * bucket.
 *
 * **Sharing it is a security decision, and the name says which question the budget answers rather than which
 * controller reached it first.** A wrong `currentPassword` on any of them deliberately does not feed the
 * persisted lockout (marking failures from a route that requires a live session would turn a stolen session
 * into a lockout DoS against the owner), so this limiter is the ONLY per-identity ceiling on guessing that
 * credential. Give each route a bucket of its own and an attacker holding a stolen session gets one window's
 * worth of guesses per route against the same password, with nothing else closing the gap — the per-IP
 * `anonymous_api` budget is 120/minute and does not bound this at all. The configuration for
 * `password_change_per_identity` says it in its own words: inventing a second number for the same question
 * is how the two drift apart.
 *
 * What the sharing costs is stated rather than hidden: an owner who mistypes while minting a recovery secret
 * also spends the budget guarding their password change and the one guarding that secret's revocation, and an
 * attacker who drains it stalls all three. That is the residual the first endpoint already accepted, unchanged
 * in kind — nothing is destroyed, the credential still works, and the window refills.
 *
 * It REFUSES OUT LOUD, which is the deliberate divergence from {@see PasswordRecoveryThrottle}. That one owes
 * the caller neutrality because its surface is pre-identity: a per-target 429 there answers "this account
 * exists" to someone who was only guessing. Every route here is reachable only through an established
 * session, so the caller already holds the identity the budget is keyed on — there is no existence left to
 * disclose, and silently accepting a request the server refuses to act on would be the worse contract.
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
final readonly class CurrentPasswordProofThrottle
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
