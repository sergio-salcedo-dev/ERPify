<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use Erpify\Iam\Identity\Application\RecoveryThrottleAuditBudget;
use Override;

/**
 * Answers the claim without a limiter, so a test about the projection is about the projection and not about
 * window arithmetic — that belongs to the adapter's own test, against the real limiter.
 *
 * @internal
 */
final readonly class FixedRecoveryThrottleAuditBudget implements RecoveryThrottleAuditBudget
{
    public function __construct(private bool $granted)
    {
    }

    #[Override]
    public function claimFor(string $email): bool
    {
        return $this->granted;
    }
}
