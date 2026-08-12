<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use Erpify\Iam\Identity\Application\RecoveryThrottleAuditBudget;
use Override;
use RuntimeException;

/**
 * Fails the claim itself, which the recorder's `try` deliberately does not cover — the claim is not the write.
 * It is what a misconfigured budget does in production, and the only way to reach the caller's `finally`.
 *
 * @internal
 */
final readonly class ThrowingRecoveryThrottleAuditBudget implements RecoveryThrottleAuditBudget
{
    #[Override]
    public function claimFor(string $email): bool
    {
        throw new RuntimeException('the audit budget could not be claimed');
    }
}
