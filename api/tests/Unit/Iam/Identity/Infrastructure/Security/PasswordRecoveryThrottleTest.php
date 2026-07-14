<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Security;

use Erpify\Iam\Identity\Infrastructure\Security\PasswordRecoveryThrottle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * @internal
 */
#[CoversClass(PasswordRecoveryThrottle::class)]
final class PasswordRecoveryThrottleTest extends TestCase
{
    public function testDeniesARequestOnceTheEmailBudgetIsSpent(): void
    {
        $throttle = $this->throttle(perEmailLimit: 2);

        $this->assertTrue($throttle->allowRequest('alice@erpify.test'));
        $this->assertTrue($throttle->allowRequest('alice@erpify.test'));
        $this->assertFalse($throttle->allowRequest('alice@erpify.test'));
    }

    public function testTheEmailKeyIsCaseFoldedSoOneMailboxSharesOneBucket(): void
    {
        $throttle = $this->throttle(perEmailLimit: 2);

        $this->assertTrue($throttle->allowRequest('Alice@Erpify.Test'));
        $this->assertTrue($throttle->allowRequest('  alice@erpify.test  '));
        $this->assertFalse($throttle->allowRequest('ALICE@ERPIFY.TEST'));
    }

    public function testAnotherTargetKeepsItsOwnBudget(): void
    {
        $throttle = $this->throttle(perEmailLimit: 1);

        $this->assertTrue($throttle->allowRequest('alice@erpify.test'));
        $this->assertFalse($throttle->allowRequest('alice@erpify.test'));
        $this->assertTrue($throttle->allowRequest('bob@erpify.test'));
    }

    public function testDeniesACompletionOnceTheSelectorBudgetIsSpentWithoutTouchingOtherSelectors(): void
    {
        $throttle = $this->throttle(perSelectorLimit: 1);

        $this->assertTrue($throttle->allowCompletion('0190e1f2-a3b4-7c5d-8e6f-1a2b3c4d5e01'));
        $this->assertFalse($throttle->allowCompletion('0190e1f2-a3b4-7c5d-8e6f-1a2b3c4d5e01'));
        $this->assertTrue($throttle->allowCompletion('0190e1f2-a3b4-7c5d-8e6f-1a2b3c4d5e02'));
    }

    private function throttle(int $perEmailLimit = 5, int $perSelectorLimit = 5): PasswordRecoveryThrottle
    {
        return new PasswordRecoveryThrottle(
            $this->factory('per_email', $perEmailLimit),
            $this->factory('per_selector', $perSelectorLimit),
        );
    }

    private function factory(string $id, int $limit): RateLimiterFactory
    {
        return new RateLimiterFactory(
            ['id' => $id, 'policy' => 'sliding_window', 'limit' => $limit, 'interval' => '1 hour'],
            new InMemoryStorage(),
        );
    }
}
