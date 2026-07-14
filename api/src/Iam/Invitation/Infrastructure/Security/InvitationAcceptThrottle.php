<?php

declare(strict_types=1);

namespace Erpify\Iam\Invitation\Infrastructure\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Per-selector budget over the invitation-accept surface — the cap on brute-forcing one invitation link's
 * secret. Exhaustion folds into the SAME opaque invalid-token wall as a dead link (never a per-selector 429,
 * which would confirm the selector exists). Shares the `token_action_per_selector` limiter with the reset
 * completion: selectors are UUIDs, so the two flows cannot collide on a key.
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
        return $this->perSelectorLimiter->create($tokenSelector)->consume()->isAccepted();
    }
}
