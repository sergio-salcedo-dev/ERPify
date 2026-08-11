<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Architecture;

use Erpify\Tests\Support\ScheduleConsumption;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Falsifiability of the half of the gate that reads the compose files: which transports a `messenger:consume`
 * command actually receives, and which service does the receiving. {@see ScheduleConsumptionGateTest} runs
 * against the real tree, which is green by construction once the wiring is right — so on its own it cannot
 * tell a rule that works from one that returns "nothing wrong" no matter what it is shown. These fixtures are
 * the states the gate has to go red on, and each is a shape that once defeated it: a transport named only in
 * a comment or an env var, a whole command parked in a comment, a command written as a plain string, a
 * receiver written after an option, and consumption by the wrong service.
 *
 * The declaration half — which schedules the tree declares at all — is falsified in
 * {@see ScheduleDeclarationRulesGateTest}.
 *
 * @internal
 */
#[CoversNothing]
final class ScheduleConsumptionRulesGateTest extends TestCase
{
    private const string FIXTURES = __DIR__ . '/Fixture/ScheduleConsumption';

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
    public function itRefusesAWholeConsumeCommandThatIsCommentedOut(): void
    {
        // Parking the old command while writing its replacement is ordinary, and the byte-level scan this
        // replaced read the parked line as live wiring — green gate, dead schedule, no trace.
        $this->assertNotContains(
            'scheduler_alpha',
            ScheduleConsumption::consumedTransportsIn(self::FIXTURES . '/compose.commented-out.yaml'),
        );
    }

    #[Test]
    public function itReadsACommandWrittenAsAPlainString(): void
    {
        // Compose accepts the string form and nothing in the repo forbids it. A rule that only understood
        // the flow-sequence form would read this file as consuming nothing — and "consuming nothing" is
        // indistinguishable from "parsed nothing" to the stale direction.
        $this->assertContains(
            'scheduler_alpha',
            ScheduleConsumption::consumedTransportsIn(self::FIXTURES . '/compose.string-command.yaml'),
        );
    }

    #[Test]
    public function itCountsAReceiverWrittenAfterAnOption(): void
    {
        // `ArgvInput` takes arguments and options in any order and `receivers` is variadic, so this IS
        // consumed. Stopping at the first option modelled the command wrongly in the direction that costs
        // something: a leftover argument written after an option stayed invisible to the stale check, whose
        // failure mode is a worker that will not boot on the next restart.
        $this->assertContains(
            'scheduler_alpha',
            ScheduleConsumption::consumedTransportsIn(self::FIXTURES . '/compose.after-option.yaml'),
        );
    }

    #[Test]
    public function itAttributesConsumptionToTheServiceThatDoesIt(): void
    {
        // Which service consumes a scheduler transport is the invariant, not that some service does: on the
        // horizontally scalable pool, an in-process clock emits one tick per replica.
        $byService = ScheduleConsumption::consumedTransportsByServiceIn(
            self::FIXTURES . '/compose.wrong-service.yaml',
        );

        $this->assertArrayHasKey('messenger_worker', $byService);
        $this->assertContains('scheduler_alpha', $byService['messenger_worker']);
        $this->assertArrayNotHasKey('scheduler_worker', $byService);
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
