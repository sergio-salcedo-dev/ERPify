<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Invitation\Infrastructure\Security;

use Erpify\Iam\Invitation\Infrastructure\Security\InvitationAcceptThrottle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * @internal
 */
#[CoversClass(InvitationAcceptThrottle::class)]
final class InvitationAcceptThrottleTest extends TestCase
{
    public function testDeniesAnAcceptOnceTheSelectorBudgetIsSpentWithoutTouchingOtherSelectors(): void
    {
        $throttle = new InvitationAcceptThrottle(new RateLimiterFactory(
            ['id' => 'token_action', 'policy' => 'sliding_window', 'limit' => 1, 'interval' => '1 hour'],
            new InMemoryStorage(),
        ));

        $this->assertTrue($throttle->allowAccept('0190b1c2-d3e4-7f5a-8b6c-1d2e3f4a5b60'));
        $this->assertFalse($throttle->allowAccept('0190b1c2-d3e4-7f5a-8b6c-1d2e3f4a5b60'));
        $this->assertTrue($throttle->allowAccept('0190b1c2-d3e4-7f5a-8b6c-1d2e3f4a5b61'));
    }
}
