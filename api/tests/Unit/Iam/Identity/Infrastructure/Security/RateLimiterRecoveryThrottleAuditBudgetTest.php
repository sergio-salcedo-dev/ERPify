<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Security;

use Erpify\Iam\Identity\Infrastructure\Security\RateLimiterRecoveryThrottleAuditBudget;
use Erpify\Iam\Identity\Infrastructure\Security\RecoveryBudgetKey;
use Erpify\Tests\Double\Clock\RateLimiterClock;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\RateLimiter\Policy\SlidingWindow;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * Against the REAL sliding-window limiter, because every property worth pinning here is a property of that
 * window's arithmetic. A budget double would assert the double.
 *
 * @internal
 */
#[CoversClass(RateLimiterRecoveryThrottleAuditBudget::class)]
final class RateLimiterRecoveryThrottleAuditBudgetTest extends TestCase
{
    private const string TARGET = 'victim@erpify.test';

    private const string LIMITER_ID = 'recovery_throttle_audit_per_email';

    #[Override]
    protected function tearDown(): void
    {
        RateLimiterClock::release();

        parent::tearDown();
    }

    public function testTheFirstClaimOfAWindowIsGranted(): void
    {
        $this->assertTrue($this->budget()->claimFor(self::TARGET));
    }

    public function testASecondClaimInTheSameWindowIsRefused(): void
    {
        $budget = $this->budget();
        $budget->claimFor(self::TARGET);

        $this->assertFalse($budget->claimFor(self::TARGET));
    }

    public function testTwoAddressesHoldIndependentSlots(): void
    {
        $budget = $this->budget();
        $budget->claimFor(self::TARGET);

        $this->assertTrue($budget->claimFor('other@erpify.test'));
    }

    public function testCasingAndSurroundingWhitespaceShareOneSlot(): void
    {
        // Divergent canonicalisation between this budget and the recovery budget it observes is a silent
        // fault: nothing breaks, the suppression simply stops corresponding to the exhaustion it suppresses.
        $budget = $this->budget();
        $budget->claimFor(self::TARGET);

        $this->assertFalse($budget->claimFor('  Victim@ERPify.TEST '));
    }

    public function testTheSlotReturnsWhenTheWindowRollsHoweverManyClaimsWereRefusedInside(): void
    {
        // THE HEARTBEAT: the suppression is temporary, and an attacker cannot extend their own silence by
        // hammering. Refusals must cost the bucket nothing, so the slot returns one interval after the row
        // that spent it however many attempts were refused in between.
        //
        // It rests on a property of `consume()` that reading SlidingWindowLimiter::reserve() suggests the
        // opposite of: its refusing branch does call `$window->add()`, but `consume()` reserves with
        // `maxTime = 0`, so a refusable request leaves through MaxWaitDurationExceededException before
        // reaching it. Swapping `consume()` for `reserve(1)` — which admits the wait and so runs that
        // `add()` — pushes the window out once per refused attempt and turns this assertion red.
        //
        // Timed on a frozen clock, because the property is window arithmetic and a real sleep bought only a
        // floor of seconds and a band a slow runner could fall out of. What the sleep's band protected against
        // is asserted directly instead: InMemoryStorage evicts an entry after `getExpirationTime()` seconds, and
        // an evicted entry is granted by ANY implementation, so the probe also proves the window it rolled is
        // still stored.
        RateLimiterClock::freeze();
        $storage = new InMemoryStorage();
        $budget = $this->budget(intervalInSeconds: 2, storage: $storage);
        $budget->claimFor(self::TARGET);

        for ($refused = 0; $refused < 20; ++$refused) {
            $budget->claimFor(self::TARGET);
        }

        RateLimiterClock::advance(1.9);
        $this->assertFalse($budget->claimFor(self::TARGET), 'The slot returned before the window rolled.');

        RateLimiterClock::advance(0.2);
        $this->assertInstanceOf(
            SlidingWindow::class,
            $storage->fetch(self::LIMITER_ID . '-' . RecoveryBudgetKey::forEmail(self::TARGET)),
            'The storage entry was evicted before the probe: a grant now is not evidence.',
        );
        $this->assertTrue($budget->claimFor(self::TARGET));
        $this->assertGreaterThan(
            0,
            RateLimiterClock::reads(),
            'The limiter never read the frozen clock: the microtime() shim is not loaded ahead of it.',
        );
    }

    private function budget(
        int $intervalInSeconds = 3600,
        InMemoryStorage $storage = new InMemoryStorage(),
    ): RateLimiterRecoveryThrottleAuditBudget {
        return new RateLimiterRecoveryThrottleAuditBudget(new RateLimiterFactory(
            [
                'id' => self::LIMITER_ID,
                'policy' => 'sliding_window',
                'limit' => 1,
                'interval' => $intervalInSeconds . ' seconds',
            ],
            $storage,
        ));
    }
}
