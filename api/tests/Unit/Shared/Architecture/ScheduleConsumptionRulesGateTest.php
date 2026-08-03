<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Architecture;

use Erpify\Tests\Support\ScheduleConsumption;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Falsifiability of the rules its sibling asserts with. {@see ScheduleConsumptionGateTest} runs against the
 * real tree, which is green by construction once the wiring is right — so on its own it cannot tell a rule
 * that works from one that returns "nothing wrong" no matter what it is shown. These fixtures are the states
 * the gate has to go red on.
 *
 * The `messenger:consume` scan is the part that most needs it. A grep for the transport name anywhere in the
 * file would pass on a compose file that only mentions it in a comment, and one that swept every quoted token
 * would count a value handed to `--time-limit` as a consumed receiver. Both shapes are here.
 *
 * @internal
 */
#[CoversNothing]
final class ScheduleConsumptionRulesGateTest extends TestCase
{
    private const string FIXTURES = __DIR__ . '/Fixture/ScheduleConsumption';

    #[Test]
    public function itSeesAScheduleNameInAnAttribute(): void
    {
        $this->assertSame(['alpha'], ScheduleConsumption::declaredScheduleNames(self::FIXTURES . '/src'));
    }

    #[Test]
    public function itDerivesTheTransportSymfonyCreates(): void
    {
        $this->assertSame('scheduler_alpha', ScheduleConsumption::transportOf('alpha'));
    }

    #[Test]
    public function itReadsATransportThatIsActuallyConsumed(): void
    {
        $this->assertContains(
            'scheduler_alpha',
            ScheduleConsumption::consumedTransportsIn(self::FIXTURES . '/compose.consumed.yaml'),
        );
    }

    #[Test]
    public function itRefusesATransportOnlyMENTIONEDInTheFile(): void
    {
        // The failure this whole gate would otherwise reproduce: the name is in the file, in a comment and in
        // an environment variable, and nothing consumes it. A grep passes; the schedule is dead.
        $this->assertNotContains(
            'scheduler_alpha',
            ScheduleConsumption::consumedTransportsIn(self::FIXTURES . '/compose.missing.yaml'),
        );
    }

    #[Test]
    public function itStopsReadingArgumentsAtTheFirstOption(): void
    {
        // Everything after `--time-limit=3600` is that command's options and their values, not receivers.
        // Counting them would let an unconsumed transport pass by being named as an option value.
        $this->assertNotContains(
            'scheduler_alpha',
            ScheduleConsumption::consumedTransportsIn(self::FIXTURES . '/compose.after-option.yaml'),
        );
    }

    #[Test]
    public function itReportsASchedulerTransportNoScheduleBacks(): void
    {
        $this->assertSame(
            ['scheduler_ghost'],
            ScheduleConsumption::unbackedSchedulerTransportsIn(self::FIXTURES . '/compose.stale.yaml', ['alpha']),
        );
    }

    #[Test]
    public function itLeavesNonSchedulerTransportsAlone(): void
    {
        // `async` is consumed and backed by no schedule, which is correct and must never be reported: the
        // stale direction is about scheduler transports only.
        $this->assertSame(
            [],
            ScheduleConsumption::unbackedSchedulerTransportsIn(self::FIXTURES . '/compose.consumed.yaml', ['alpha']),
        );
    }
}
