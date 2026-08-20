<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Security;

use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Per-target budgets over the password-recovery surface, consumed at the controller edge (where
 * `login_throttling` and the global per-IP limiter already live). The contract is NEUTRALITY: exhaustion
 * must never change the response shape — the caller folds a denied request into the same uniform outcome
 * the endpoint always produces (202 for forgot, the opaque invalid-token wall for a completion) — because
 * a per-target 429 would be an oracle over which accounts or selectors exist and are under attack.
 *
 * Factories are bound explicitly to the `password_recovery_per_email` and `token_action_per_selector`
 * limiters declared in `config/packages/rate_limiter.yaml`.
 */
final readonly class PasswordRecoveryThrottle
{
    public function __construct(
        #[Autowire(service: 'limiter.password_recovery_per_email')]
        private RateLimiterFactoryInterface $perEmailLimiter,
        #[Autowire(service: 'limiter.token_action_per_selector')]
        private RateLimiterFactoryInterface $perSelectorLimiter,
    ) {
    }

    /**
     * Consumes the per-email forgot budget. The key is case-folded by {@see RecoveryBudgetKey} so re-requests
     * for the same mailbox in different casings share one bucket; the budget is spent for EVERY requested
     * address, known or not, so the limiter itself cannot be probed for existence.
     */
    public function allowRequest(#[SensitiveParameter] string $email): bool
    {
        return $this->perEmailLimiter
            ->create(RecoveryBudgetKey::forEmail($email))
            ->consume()
            ->isAccepted()
        ;
    }

    /**
     * Consumes the per-selector completion budget — the cap on brute-forcing one link's secret.
     */
    public function allowCompletion(string $tokenSelector): bool
    {
        return $this->perSelectorLimiter->create($tokenSelector)->consume()->isAccepted();
    }
}
