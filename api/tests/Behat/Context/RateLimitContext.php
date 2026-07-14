<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Context;

use Behat\Behat\Context\Context;
use Behat\Step\Given;
use Erpify\Tests\Behat\Context\Abstraction\AbstractContext;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Lets scenarios prime a rate-limit budget before driving the HTTP client.
 *
 * The Symfony test cache app pool is `cache.adapter.array` (see
 * `config/packages/test/cache.yaml`), which is reset between requests by the
 * `services_resetter` on `kernel.terminate`. That makes it impossible to express
 * "5 requests succeed, the 6th gets a 429" by sending six sequential
 * `iSendARequestTo` steps — every request starts with a fresh, full budget. Reaching
 * into the limiter service from a Given step shares the SAME container instance the
 * listener resolves at request time, but does NOT trigger `kernel.terminate`, so the
 * consumed tokens are visible to the very next HTTP step before any reset fires —
 * and ONLY to that one: prime immediately before the single request that must see it.
 *
 * Mirrors the autowire binding declared on
 * {@see \Erpify\Shared\ErrorContract\Infrastructure\Http\EventListener\RateLimitListener::__construct} and the
 * per-target throttles ({@see \Erpify\Iam\Identity\Infrastructure\Security\PasswordRecoveryThrottle},
 * {@see \Erpify\Iam\Invitation\Infrastructure\Security\InvitationAcceptThrottle}).
 */
final class RateLimitContext extends AbstractContext implements Context
{
    public function __construct(
        #[Autowire(service: 'limiter.anonymous_api')]
        private readonly RateLimiterFactoryInterface $anonymousApiLimiter,
        #[Autowire(service: 'limiter.password_recovery_per_email')]
        private readonly RateLimiterFactoryInterface $perEmailLimiter,
        #[Autowire(service: 'limiter.token_action_per_selector')]
        private readonly RateLimiterFactoryInterface $perSelectorLimiter,
    ) {
    }

    /**
     * Consume the entire `anonymous_api` budget for `$clientIp` so the next request from
     * that IP lands on the rejected path. `127.0.0.1` is FoB's KernelBrowser default
     * `REMOTE_ADDR`, which is also what {@see RateLimitListener::resolveLimiterKey}
     * derives via `Request::getClientIp()`.
     */
    #[Given('the anonymous API rate-limit budget is exhausted for client :clientIp')]
    public function theAnonymousApiRateLimitBudgetIsExhausted(string $clientIp): void
    {
        $this->exhaust($this->anonymousApiLimiter->create($clientIp), 'anonymous_api');
    }

    /**
     * Key derivation mirrors {@see \Erpify\Iam\Identity\Infrastructure\Security\PasswordRecoveryThrottle}:
     * the bucket is keyed by the case-folded email.
     */
    #[Given('the password-recovery budget is exhausted for email :email')]
    public function thePasswordRecoveryBudgetIsExhaustedForEmail(string $email): void
    {
        $this->exhaust(
            $this->perEmailLimiter->create(\mb_strtolower(\trim($email))),
            'password_recovery_per_email',
        );
    }

    #[Given('the token-action budget is exhausted for selector :selector')]
    public function theTokenActionBudgetIsExhaustedForSelector(string $selector): void
    {
        $this->exhaust($this->perSelectorLimiter->create($selector), 'token_action_per_selector');
    }

    /**
     * Burn one token at a time until the limiter rejects — robust to whatever the env-configured limit
     * happens to be.
     */
    private function exhaust(LimiterInterface $limiter, string $limiterName): void
    {
        $safetyCap = 10_000;

        for ($i = 0; $i < $safetyCap; ++$i) {
            $rateLimit = $limiter->consume(1);

            if (!$rateLimit->isAccepted()) {
                return;
            }
        }

        self::fail(\sprintf(
            'Could not exhaust the %s budget within %d attempts — check the limit configured in api/.env.',
            $limiterName,
            $safetyCap,
        ));
    }
}
