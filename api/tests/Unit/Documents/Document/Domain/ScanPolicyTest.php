<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Documents\Document\Domain;

use Erpify\Documents\Document\Domain\ScanPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The scan accounting's constants and the relations between them. The SQL of a return reads the ladders from
 * {@see ScanPolicy::ladder()} and the caps from the constants, so these are what the delays and the caps are; the
 * functional ladder-walk tests prove the database applies them.
 *
 * @internal
 */
#[CoversClass(ScanPolicy::class)]
final class ScanPolicyTest extends TestCase
{
    #[Test]
    public function countedReturnsUseTheFirstTwoEntriesAndOutageReturnsWalkTheWholeLadder(): void
    {
        $this->assertSame([60, 300], ScanPolicy::ladder(true));
        $this->assertSame([60, 300, 900, 3600], ScanPolicy::ladder(false));
    }

    #[Test]
    public function onlyTheReturnsBeforeTheCapWaitAndTheLastLadderEntryRepeats(): void
    {
        $ladder = ScanPolicy::ladder(false);
        $horizon = 0;

        // Return n backs off by entry min(n, count) and the MAX_OUTAGE_RETURNS-th ends the document instead.
        for ($return = 1; $return < ScanPolicy::MAX_OUTAGE_RETURNS; ++$return) {
            $horizon += $ladder[\min($return, \count($ladder)) - 1] ?? 0;
        }

        $this->assertSame(60 + 300 + 900 + 6 * 3600, $horizon, 'returns 1-9 (22,860 s): three entries, then 6 hours');

        $jitteredMaximum = $horizon * (1 + ScanPolicy::JITTER);
        $this->assertGreaterThan($jitteredMaximum, ScanPolicy::STUCK_BOUND_SECONDS);
    }

    #[Test]
    public function theTimingConstantsKeepTheirOrder(): void
    {
        $this->assertLessThan(ScanPolicy::LEASE_SECONDS, ScanPolicy::CLAMD_MAX_SCAN_TIME_MILLISECONDS / 1000);
        $this->assertLessThan(ScanPolicy::DEADLINE_SECONDS, ScanPolicy::CLAMD_MAX_SCAN_TIME_MILLISECONDS / 1000);
        $this->assertLessThan(ScanPolicy::HEARTBEAT_SECONDS, ScanPolicy::READ_TIMEOUT_SECONDS);
        $heartbeats = ScanPolicy::HEARTBEAT_SECONDS * 2;
        $this->assertLessThan(ScanPolicy::LEASE_SECONDS, $heartbeats, 'one missed beat is survived');
        $this->assertLessThan(ScanPolicy::DEADLINE_SECONDS, ScanPolicy::LEASE_SECONDS);
        $this->assertLessThan(
            ScanPolicy::MAX_OUTAGE_RETURNS,
            ScanPolicy::OUTAGE_ALARM_AT,
            'the alarm fires before the cap ends the document',
        );
    }
}
