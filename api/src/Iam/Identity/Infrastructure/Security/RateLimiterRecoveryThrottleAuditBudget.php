<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Security;

use Erpify\Iam\Identity\Application\RecoveryThrottleAuditBudget;
use Override;
use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * The audit budget over `limiter.recovery_throttle_audit_per_email`, keyed by the same canonical address as
 * the recovery budget it observes ({@see RecoveryBudgetKey}).
 *
 * It is a separate collaborator from {@see PasswordRecoveryThrottle} rather than a third method on it, and the
 * split is by reason-to-change: that class answers "may this request proceed?" and its contract is that
 * exhaustion never reaches the response. This one answers "has this refusal already been observed?" — a
 * question about the trail, on a path where the response is already decided. Folding it in would also put it
 * beside `allowCompletion()`, whose silence is a different security property entirely.
 *
 * A refused claim costs the bucket nothing, which is what makes one call enough. `consume()` reserves with
 * `maxTime = 0`, so a request that cannot be served immediately leaves through
 * `MaxWaitDurationExceededException` before the refusing branch of `SlidingWindowLimiter::reserve()` reaches
 * its `add()` — measured, because that `add()` is plainly visible in the source and reading it alone suggests
 * the opposite. The consequence worth stating: refusals do not push the window out, so the slot comes back one
 * interval after the row that spent it and an attacker cannot extend their own silence by hammering.
 *
 * Two callers crossing the window boundary together can both be granted. `lock_factory: null` on this limiter
 * admits the same drift, and the duplicate row it can produce is the residue this control accepts rather than
 * the defect it guards: a second row is operator noise, an unbounded row count is the amplifier.
 */
#[AsAlias(RecoveryThrottleAuditBudget::class)]
final readonly class RateLimiterRecoveryThrottleAuditBudget implements RecoveryThrottleAuditBudget
{
    public function __construct(
        #[Autowire(service: 'limiter.recovery_throttle_audit_per_email')]
        private RateLimiterFactoryInterface $auditLimiter,
    ) {
    }

    #[Override]
    public function claimFor(#[SensitiveParameter] string $email): bool
    {
        return $this->auditLimiter
            ->create(RecoveryBudgetKey::forEmail($email))
            ->consume()
            ->isAccepted()
        ;
    }
}
