<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Cli;

use Erpify\Iam\Identity\Application\ReconcileErasedSubjectReferences;
use Erpify\Iam\Identity\Domain\Repository\LiveIdentityDirectory;
use Erpify\Iam\Identity\Infrastructure\Cli\ReconcileErasedSubjectReferencesCommand;
use Erpify\Organization\Membership\Domain\Entity\Membership;
use Erpify\Tests\Unit\Iam\Identity\Application\InMemoryLiveIdentityDirectory;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\FixedPersonResourceReferences;
use Erpify\Tests\Unit\Shared\Privacy\Infrastructure\Double\FixedPersonReferenceSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The exit code is the contract: a monitoring check calls this command and reads nothing else, so a
 * divergence that prints loudly while exiting `0` is the same as no control at all.
 *
 * The report itself must also stay actionable — an operator who sees the failure needs the offending ids,
 * the place each one is still held, and the repair. WHICH place is what decides whether they are looking at
 * an unanonymised audit trail or at a membership that outlived the person it admitted.
 *
 * @internal
 */
#[CoversClass(ReconcileErasedSubjectReferencesCommand::class)]
final class ReconcileErasedSubjectReferencesCommandTest extends TestCase
{
    private const string MEMBERSHIP_AXIS = Membership::class . '::$userId';

    private const string DANGLING_ID = '0190f500-0000-7000-8000-0000000000d1';

    private const string OTHER_DANGLING_ID = '0190f500-0000-7000-8000-0000000000d2';

    #[Test]
    public function itSucceedsAndSaysHowMuchItCheckedWhenNothingIsUnreconciled(): void
    {
        $tester = $this->tester([], []);

        $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        // The axes BY NAME, not just the all-clear and not just a count: a control silently reduced to one
        // place would otherwise print exactly what a full clean sweep prints, and the exit code — all a
        // monitoring check reads — would stay zero.
        $this->assertStringContainsString('audit_log.resource_id', $display);
        $this->assertStringContainsString(self::MEMBERSHIP_AXIS, $display);
    }

    #[Test]
    public function itFailsAndNamesEachPlaceItsIdsAndTheRepair(): void
    {
        $tester = $this->tester([self::DANGLING_ID], [self::OTHER_DANGLING_ID]);

        $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('2 person reference(s)', $display);
        $this->assertStringContainsString('audit_log.resource_id', $display);
        // The FULL key, because two modules can hold a `Membership` and a short heading would merge them.
        $this->assertStringContainsString(self::MEMBERSHIP_AXIS, $display);
        $this->assertStringContainsString(self::DANGLING_ID, $display);
        $this->assertStringContainsString(self::OTHER_DANGLING_ID, $display);
        // `--force` included: without it the repair asks for a confirmation, and a run that cannot be asked is
        // refused rather than answered for.
        $this->assertStringContainsString('identity:gdpr:erase-subject <id> --force', $display);
    }

    #[Test]
    public function itNamesEveryAxisItCheckedEvenWhenOnlySomeOfThemDiverge(): void
    {
        // The axis that RECONCILES is the one at stake: it appears nowhere among the findings, so unless the
        // failure report names the axes it checked, an operator reading a real divergence cannot tell a
        // five-axis sweep from a control that lost a source — the ambiguity the clean run already refuses.
        $tester = $this->tester([self::DANGLING_ID], []);

        $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString(self::MEMBERSHIP_AXIS, $display);
    }

    #[Test]
    public function itSeparatesABrokenProbeFromARealDivergenceInTheExitCode(): void
    {
        // The exit code is the whole contract, so two outcomes sharing one non-zero value means an automated
        // check cannot tell "a person survived their erasure" — actionable, with a documented repair — from
        // "the check could not run", which is an infrastructure fault with no compliance meaning at all.
        $identities = $this->createStub(LiveIdentityDirectory::class);
        $identities->method('existingIdsAmong')->willThrowException(new RuntimeException('connection lost'));

        $reconciler = new ReconcileErasedSubjectReferences(
            new FixedPersonResourceReferences([self::DANGLING_ID]),
            [],
            $identities,
        );
        $tester = new CommandTester(new ReconcileErasedSubjectReferencesCommand($reconciler));

        $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertSame(Command::INVALID, $tester->getStatusCode());
        $this->assertNotSame(Command::FAILURE, $tester->getStatusCode());
        // And it must not read as a clean sweep either: silence plus a non-zero code would send an operator
        // looking for a divergence that was never established.
        $this->assertStringContainsString('could not be completed', $display);
    }

    #[Test]
    public function itSeparatesAMiswiredControlFromADivergenceInTheExitCodeAnOperatorActuallySees(): void
    {
        // Driven through `Application`, not `CommandTester`. `CommandTester` calls `Command::run()`, and the
        // only layer that turns an uncaught throwable into an exit code is `Application::run()` — so a test
        // stopping at the former cannot observe the number a cron reads, which is the entire declared
        // contract. Left uncaught, the wiring bug's `LogicException` carries no code, `Application` coerces
        // that to 1, and 1 is `FAILURE`: "a person survived their erasure, go repair them", on a run that
        // established nothing about any subject and left one place unchecked.
        $application = new Application();
        $application->setAutoExit(false);
        $application->addCommand($this->command([
            new FixedPersonReferenceSource(self::MEMBERSHIP_AXIS, []),
            new FixedPersonReferenceSource(self::MEMBERSHIP_AXIS, []),
        ]));
        $output = new BufferedOutput();

        $exitCode = $application->run(
            new ArrayInput(['command' => 'identity:gdpr:reconcile-subject-references']),
            $output,
        );

        // `INVALID` and not `FAILURE`, which is the number this would carry if the catch were removed.
        $this->assertSame(Command::INVALID, $exitCode);
        // Only the code is asserted here, and that is not an oversight: the suite runs with
        // `SHELL_VERBOSITY=-1` (tools/phpunit/phpunit.dist.xml), which `Application::configureIO()` turns
        // into VERBOSITY_QUIET — so nothing this command prints reaches the buffer on this path. What the
        // operator READS is pinned by the sibling test below, through a harness that does not reconfigure
        // the output.
        $this->assertSame('', $output->fetch());
    }

    #[Test]
    public function itTellsAMiswiredControlApartFromAFailedReadInTheReportItPrints(): void
    {
        // The exit code deliberately merges the two causes — both mean "no verdict, repair nothing" — so the
        // report is the only place they part company, and they part company because the repairs differ: a
        // wiring change by whoever added the source, not the infrastructure fix a failed read asks for.
        // Collapsing the two catches into one would take this distinction with it.
        $tester = new CommandTester($this->command([
            new FixedPersonReferenceSource(self::MEMBERSHIP_AXIS, []),
            new FixedPersonReferenceSource(self::MEMBERSHIP_AXIS, []),
        ]));

        $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertSame(Command::INVALID, $tester->getStatusCode());
        $this->assertStringContainsString('miswired', $display);
        $this->assertStringNotContainsString('could not be completed', $display);
    }

    /**
     * @param list<FixedPersonReferenceSource> $sources
     */
    private function command(array $sources): ReconcileErasedSubjectReferencesCommand
    {
        return new ReconcileErasedSubjectReferencesCommand(new ReconcileErasedSubjectReferences(
            new FixedPersonResourceReferences([]),
            $sources,
            new InMemoryLiveIdentityDirectory(),
        ));
    }

    /**
     * Driven through the real reconciler over in-memory doubles: an empty
     * {@see InMemoryLiveIdentityDirectory} resolves every referenced id to a gone identity, so what the
     * doubles hold is exactly the set of divergences.
     *
     * @param list<string> $namedInTheTrail
     * @param list<string> $heldByMembership
     */
    private function tester(array $namedInTheTrail, array $heldByMembership): CommandTester
    {
        $reconciler = new ReconcileErasedSubjectReferences(
            new FixedPersonResourceReferences($namedInTheTrail),
            [new FixedPersonReferenceSource(self::MEMBERSHIP_AXIS, $heldByMembership)],
            new InMemoryLiveIdentityDirectory(),
        );

        return new CommandTester(new ReconcileErasedSubjectReferencesCommand($reconciler));
    }
}
