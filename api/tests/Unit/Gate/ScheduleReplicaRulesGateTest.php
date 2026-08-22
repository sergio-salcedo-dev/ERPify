<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Tests\Support\ScheduleConsumption;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Falsifiability of the replica half of the schedule gate: how many replicas a compose file declares for the
 * service that consumes scheduler transports. {@see ScheduleConsumptionGateTest} asserts it over the real
 * tree, which is pinned — so on its own that assertion is green whether the rule reads the count or reads
 * nothing at all.
 *
 * These fixtures are the states the rule has to tell apart: a count above one; NO count declared, in both of
 * its shapes (a `deploy` block carrying only resource limits — what a service acquires when someone adds
 * limits and never thinks about the clock — and no `deploy` block at all, which takes a different branch of
 * the reader); a count written as an interpolation whose real value lives in an env file no gate opens; and
 * the two ways there is nothing to read at all.
 *
 * Every exception case anchors its MESSAGE, not just its class. The method throws the same
 * `RuntimeException` from three sites and `Yaml\ParseException` extends it as well, so class-only assertions
 * would let a renamed service or a broken fixture keep a test green while it silently began proving a
 * different guard.
 *
 * Why the count matters at all is in {@see ScheduleConsumption::SCHEDULER_CONSUMER_REPLICAS}: ticks come from
 * an in-process clock and no schedule carries `->lock()`, so a second replica can duplicate a tick rather
 * than share it — the shared checkpoint narrows that to a window, it does not close it.
 *
 * @internal
 */
#[CoversNothing]
final class ScheduleReplicaRulesGateTest extends TestCase
{
    private const string FIXTURES = __DIR__ . '/Fixture/ScheduleConsumption';

    #[Test]
    public function itReadsTheReplicaCountAConsumerDeclares(): void
    {
        // The state every other assertion of the gate reports as correct wiring: right service, right
        // transport, nothing stale — and two clocks.
        $this->assertSame(
            2,
            ScheduleConsumption::declaredReplicasOf(
                self::FIXTURES . '/compose.scaled-consumer.yaml',
                'scheduler_worker',
            ),
        );
    }

    #[Test]
    public function itReadsADeployBlockWithoutAReplicaCountAsNoCount(): void
    {
        // A `deploy` block holding only resource limits must read as "no count declared", not as a parse
        // failure and not as a one. Compose supplies the one; the file records nothing, and that difference
        // is the whole point of asking the deployed file to write it down.
        $this->assertNull(
            ScheduleConsumption::declaredReplicasOf(
                self::FIXTURES . '/compose.unpinned-consumer.yaml',
                'scheduler_worker',
            ),
        );
    }

    #[Test]
    public function itReadsAServiceWithNoDeployBlockAsNoCount(): void
    {
        // The other branch of the same answer, and the one nothing exercised: `array_key_exists` on a null
        // `deploy` is a TypeError, so without this case that half of the guard was falsified only by the
        // real tree — which is precisely what this class exists not to rely on.
        $this->assertNull(
            ScheduleConsumption::declaredReplicasOf(
                self::FIXTURES . '/compose.no-deploy-block.yaml',
                'scheduler_worker',
            ),
        );
    }

    #[Test]
    public function itRefusesAReplicaCountItCannotEvaluate(): void
    {
        // `replicas: ${SCHEDULER_REPLICAS:-1}` looks pinned in the file and is set in an env file no gate
        // reads. Returning null here would let the deployed-file assertion report a missing pin — a red for
        // the wrong reason — and returning 1 would report a pin that does not exist.
        //
        // The message is anchored, not just the class: three sites in this method throw the same
        // `RuntimeException`, and `Yaml\ParseException` extends it too, so a broken fixture or a renamed
        // service would keep this green while proving a different guard entirely.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/cannot evaluate/');

        ScheduleConsumption::declaredReplicasOf(
            self::FIXTURES . '/compose.interpolated-replicas.yaml',
            'scheduler_worker',
        );
    }

    #[Test]
    public function itRefusesToReadAConsumerTheFileDoesNotDeclare(): void
    {
        // A renamed service would otherwise make the replica check vacuous: nothing to read is not the same
        // claim as nothing wrong.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/is not declared in/');

        ScheduleConsumption::declaredReplicasOf(
            self::FIXTURES . '/compose.unpinned-consumer.yaml',
            'messenger_worker',
        );
    }

    #[Test]
    public function itRefusesAComposeFileNoServiceIsHeldResponsibleFor(): void
    {
        // The pairing between the deployed compose file and its consumer had no red at all while it was an
        // inline offset read: PHPStan resolved the two literals, which both forbade an assertion about it
        // and left it unchecked. Measured — dropping the deployed file from the map kept `php.stan` at 0.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/No service is held responsible/');

        ScheduleConsumption::replicaConsumerOf('compose.staging.yaml');
    }

    #[Test]
    public function itRefusesADocumentThatDeclaresNoServices(): void
    {
        // Without this the guard had no red of its own: `$parsed['services'] ?? null` on a services-less
        // document yields null silently, so removal fell through to the absent-service throw — same class,
        // and until the messages were anchored, indistinguishable.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/declares no services to read/');

        ScheduleConsumption::declaredReplicasOf(
            self::FIXTURES . '/compose.no-services.yaml',
            'scheduler_worker',
        );
    }
}
