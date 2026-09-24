<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Tests\Support\ScheduleConsumption;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Yaml\Exception\ParseException;

/**
 * Falsifiability of the stack merge behind the schedule-consumption gate. Compose never runs `compose.yaml`
 * alone: `make/config.mk` layers `compose.dev.yaml` or `compose.prod.yaml` over it, and an overlay's
 * `command` replaces the base's whole, `command: ~` removes it, and a service redefined without one inherits
 * it. Each fixture pair below is a stack whose merged answer differs from what either file says on its own —
 * which is exactly what a file-by-file sweep, green over a `command:` planted in the dev overlay, got wrong.
 *
 * The single-file shapes (comments, string commands, receivers after options, the wrong service) are
 * falsified in {@see ScheduleConsumptionRulesGateTest}.
 *
 * @internal
 */
#[CoversNothing]
final class ScheduleStackMergeRulesGateTest extends TestCase
{
    private const string FIXTURES = __DIR__ . '/Fixture/ScheduleConsumption';

    #[Test]
    public function anOverlayThatLeavesTheCommandAloneInheritsIt(): void
    {
        $byService = ScheduleConsumption::consumedTransportsByServiceIn(
            self::FIXTURES . '/compose.overlay-base.yaml',
            self::FIXTURES . '/compose.overlay-silent.yaml',
        );

        $this->assertSame(['async', 'scheduler_alpha'], $byService['messenger_worker'] ?? null);
    }

    #[Test]
    public function anOverlayCommandReplacesTheBaseCommandWhole(): void
    {
        // The dev-overlay hole: read file by file, the base still names scheduler_alpha and the gate stays
        // green while the stack Compose actually runs never consumes it.
        $this->assertSame(
            ['async'],
            ScheduleConsumption::consumedTransportsByServiceIn(
                self::FIXTURES . '/compose.overlay-base.yaml',
                self::FIXTURES . '/compose.overlay-replaces.yaml',
            )['messenger_worker'] ?? null,
        );
    }

    #[Test]
    public function anOverlayNullingTheCommandLeavesTheServiceConsumingNothing(): void
    {
        $this->assertArrayNotHasKey(
            'messenger_worker',
            ScheduleConsumption::consumedTransportsByServiceIn(
                self::FIXTURES . '/compose.overlay-base.yaml',
                self::FIXTURES . '/compose.overlay-nulls.yaml',
            ),
        );
    }

    #[Test]
    public function aGhostTransportAddedByAnOverlayIsReportedStale(): void
    {
        $this->assertSame(
            ['scheduler_ghost'],
            ScheduleConsumption::unbackedSchedulerTransportsIn(
                ['alpha'],
                self::FIXTURES . '/compose.overlay-base.yaml',
                self::FIXTURES . '/compose.overlay-ghost.yaml',
            ),
        );
    }

    #[Test]
    public function aServiceOnlyTheOverlayDeclaresCounts(): void
    {
        $byService = ScheduleConsumption::consumedTransportsByServiceIn(
            self::FIXTURES . '/compose.overlay-base.yaml',
            self::FIXTURES . '/compose.overlay-introduces.yaml',
        );

        $this->assertSame(['async', 'scheduler_alpha'], $byService['messenger_worker'] ?? null);
        $this->assertSame(['scheduler_beta'], $byService['scheduler_worker'] ?? null);
    }

    #[Test]
    public function anEmptyStackIsRefusedRatherThanReadAsConsumingNothing(): void
    {
        // Nothing consumed and nothing stale is exactly what an empty read would report, so it must throw.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('No compose file was given to read.');

        ScheduleConsumption::consumedTransportsByServiceIn();
    }

    #[Test]
    public function aStackMemberDeclaringNoServicesIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('declares no services to read.');

        ScheduleConsumption::consumedTransportsByServiceIn(
            self::FIXTURES . '/compose.overlay-base.yaml',
            self::FIXTURES . '/compose.no-services.yaml',
        );
    }

    #[Test]
    public function anOverlayEmptyingTheCommandLeavesTheServiceConsumingNothing(): void
    {
        $this->assertArrayNotHasKey(
            'messenger_worker',
            ScheduleConsumption::consumedTransportsByServiceIn(
                self::FIXTURES . '/compose.overlay-base.yaml',
                self::FIXTURES . '/compose.overlay-empties.yaml',
            ),
        );
    }

    #[Test]
    public function aComposeResetTagFailsTheReadRatherThanBeingIgnored(): void
    {
        // `!reset` is not modelled. Read past, it would leave the base's receivers standing over a stack that
        // runs none of them, so the parser's refusal is the behaviour this pins.
        $this->expectException(ParseException::class);

        ScheduleConsumption::consumedTransportsByServiceIn(
            self::FIXTURES . '/compose.overlay-base.yaml',
            self::FIXTURES . '/compose.overlay-reset.yaml',
        );
    }
}
