<?php

declare(strict_types=1);

namespace Erpify\Iam\Invitation\Infrastructure\Security;

use Erpify\Shared\Token\Domain\SelectorBudgetKey;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Per-selector budget over the invitation-accept surface — the cap on brute-forcing one invitation link's
 * secret. Exhaustion folds into the SAME opaque invalid-token wall as a dead link (never a per-selector 429,
 * which would confirm the selector exists). Shares the `token_action_per_selector` limiter with the reset
 * completion and the recovery-secret redemption — three surfaces on one namespace, which selectors being
 * UUIDs is what keeps collision-free.
 *
 * The key is folded through {@see SelectorBudgetKey} rather than used raw, and that is not tidiness: a
 * selector is a UUID, the column it selects is a Postgres-native `uuid` comparing case-insensitively, and
 * `Uuid::isValid()` accepts either case — so a raw key gave one row thousands of buckets and the limit
 * silently stopped being a limit. Measured and fixed on all three surfaces at once.
 */
final readonly class InvitationAcceptThrottle
{
    public function __construct(
        #[Autowire(service: 'limiter.token_action_per_selector')]
        private RateLimiterFactoryInterface $perSelectorLimiter,
    ) {
    }

    public function allowAccept(string $tokenSelector): bool
    {
        return $this->perSelectorLimiter->create(SelectorBudgetKey::of($tokenSelector))->consume()->isAccepted();
    }
}
