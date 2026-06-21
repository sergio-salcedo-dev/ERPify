<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Context;

use Behat\Behat\Context\Context;
use Behat\Step\Given;
use Erpify\Tests\Behat\Context\Abstraction\AbstractContext;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Lets scenarios prime the anonymous API rate-limit budget before driving the HTTP client.
 *
 * The Symfony test cache app pool is `cache.adapter.array` (see
 * `config/packages/test/cache.yaml`), which is reset between requests by the
 * `services_resetter` on `kernel.terminate`. That makes it impossible to express
 * "5 requests succeed, the 6th gets a 429" by sending six sequential
 * `iSendARequestTo` steps — every request starts with a fresh, full budget. Reaching
 * into the limiter service from a Given step shares the SAME container instance the
 * listener resolves at request time, but does NOT trigger `kernel.terminate`, so the
 * consumed tokens are visible to the very next HTTP step before any reset fires.
 *
 * Mirrors the autowire binding declared on
 * {@see \Erpify\Shared\ErrorContract\Infrastructure\Http\EventListener\RateLimitListener::__construct}.
 */
final class RateLimitContext extends AbstractContext implements Context
{
    public function __construct(
        #[Autowire(service: 'limiter.anonymous_api')]
        private readonly RateLimiterFactoryInterface $anonymousApiLimiter,
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
        $limiter = $this->anonymousApiLimiter->create($clientIp);
        // Burn one token at a time until the limiter rejects — robust to whatever the
        // env-configured limit happens to be (5 in test, 120 in prod).
        $safetyCap = 10_000;

        for ($i = 0; $i < $safetyCap; ++$i) {
            $rateLimit = $limiter->consume(1);

            if (!$rateLimit->isAccepted()) {
                return;
            }
        }

        self::fail(\sprintf(
            'Could not exhaust the anonymous_api budget for "%s" within %d attempts'
            . ' — check the limit configured in api/.env.test.',
            $clientIp,
            $safetyCap,
        ));
    }
}
