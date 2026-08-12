<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Security;

use Erpify\Iam\Identity\Infrastructure\Security\RateLimiterRecoveryThrottleAuditBudget;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
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
        // The two seconds are not padding. A shorter window cannot tell this apart from bucket eviction:
        // InMemoryStorage keeps an entry for `getExpirationTime()` seconds, which truncates to `(int)` and so
        // collapses onto the window itself at one second — the entry vanishes, a fresh window is minted, and
        // every implementation looks alive. At two seconds the roll (2.0s) and the eviction (~3.0s) separate,
        // and this probe reads between them.
        $budget = $this->budget(intervalInSeconds: 2);
        $budget->claimFor(self::TARGET);

        for ($refused = 0; $refused < 20; ++$refused) {
            $budget->claimFor(self::TARGET);
        }

        $probeAt = \microtime(true);
        \usleep(2_200_000);
        $elapsed = \microtime(true) - $probeAt;

        // Past ~3.0s InMemoryStorage evicts the entry, a fresh window is minted and the claim is granted
        // whatever the implementation does — so a slow runner would turn this gate green instead of red.
        // Assert the probe landed inside the discriminating band rather than trusting that it did.
        $this->assertLessThan(3.0, $elapsed, 'Probed after the storage entry could have been evicted: not evidence.');
        $this->assertGreaterThan(2.0, $elapsed, 'Probed before the window rolled: not evidence.');
        $this->assertTrue($budget->claimFor(self::TARGET));
    }

    private function budget(int $intervalInSeconds = 3600): RateLimiterRecoveryThrottleAuditBudget
    {
        return new RateLimiterRecoveryThrottleAuditBudget(new RateLimiterFactory(
            [
                'id' => 'recovery_throttle_audit_per_email',
                'policy' => 'sliding_window',
                'limit' => 1,
                'interval' => $intervalInSeconds . ' seconds',
            ],
            new InMemoryStorage(),
        ));
    }
}
