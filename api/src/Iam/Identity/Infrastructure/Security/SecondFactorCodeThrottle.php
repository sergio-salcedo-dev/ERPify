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
 * The per-identity budget on presenting a SECOND-FACTOR CODE, consumed at the controller edge before the use case
 * runs. Its own bucket rather than {@see CurrentPasswordProofThrottle}'s, because it answers a different question:
 * how many guesses at a six-digit code an identity gets, not how many at its password. A wrong code feeds no
 * persisted lockout, so this limiter is the only ceiling on guessing one.
 *
 * Every route that verifies a code spends this one bucket, so a stolen session cannot multiply its guesses by
 * spreading them across routes. On a route reachable only through a session it REFUSES OUT LOUD, like the
 * password-proof budget, because the caller already holds the identity it is keyed on; a pre-session surface owes
 * the caller the uniform refusal of the handshake instead and must not surface this 429.
 *
 * It is an ATTENUATOR, never the wall: `lock_factory: null` lets a burst of concurrent requests overrun it, and the
 * filesystem cache pool it lives in is reset on every deploy (`rate_limiter.yaml`). The wall for the enrolment's
 * confirmation is the per-enrolment cap counted on the factor row ({@see
 * \Erpify\Iam\Identity\Domain\Entity\TotpFactor::MAX_FAILED_CONFIRMATIONS}); every other route that verifies a
 * code needs a persisted counter of its own on the factor row before it may rely on this bucket.
 *
 * The refusal reuses {@see RateLimitExceeded} (429 `rate-limited`), so no marker joins the error contract, and it
 * stamps its {@see RateLimitSnapshot} on the request so the 429 carries the headers of the budget that said no.
 * The identity id stays inside the exception as its `limiterKey`, never in the body or the per-error log line.
 */
final readonly class SecondFactorCodeThrottle
{
    public function __construct(
        #[Autowire(service: 'limiter.second_factor_code_per_identity')]
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

        if ($request instanceof Request) {
            $snapshot->stampOn($request);
        }

        throw $snapshot->refusal();
    }
}
