<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Cli;

use Erpify\Iam\Identity\Application\StoredIdentityIntegrity;
use Erpify\Iam\Identity\Infrastructure\Cli\InspectStoredIdentityIntegrityCommand;
use Erpify\Shared\Audit\Application\AuditLogger;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Domain\AuditResource;
use Erpify\Tests\Unit\Iam\Identity\Application\FailingStoredIdentityIntegrity;
use Erpify\Tests\Unit\Iam\Identity\Application\FixedStoredIdentityIntegrity;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\FailingAuditLogger;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\RecordingAuditLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The exit code is the contract: a caller that runs this unattended reads nothing else, so the three
 * outcomes have to stay distinguishable — and nothing schedules it yet, so the contract is kept for a
 * consumer that does not exist rather than repaired after one misreads it.
 *
 * `INVALID` is the one no scenario can exercise — a probe fails when the database cannot answer, which a run
 * cannot arrange without breaking the suite carrying it — and it is also the one that matters most, because a
 * failed read reported as `SUCCESS` is a control certifying a table it never managed to look at.
 *
 * @internal
 */
#[CoversClass(InspectStoredIdentityIntegrityCommand::class)]
final class InspectStoredIdentityIntegrityCommandTest extends TestCase
{
    #[Test]
    public function itSucceedsAndNamesTheColumnsItCheckedWhenNothingHasDrifted(): void
    {
        $auditLogger = new RecordingAuditLogger();
        $tester = $this->tester(new FixedStoredIdentityIntegrity([], 0), $auditLogger);

        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        // The columns BY NAME: a green here is a statement about these two reads and nothing else, and a
        // bare "all clear" would read as a guarantee about the database.
        $this->assertStringContainsString('identity_user.roles', $tester->getDisplay());
        $this->assertStringContainsString('identity_user.password_hash', $tester->getDisplay());
        // A trail that gains a row every time a scheduled check finds nothing buries the runs that did.
        $this->assertSame([], $auditLogger->records);
    }

    #[Test]
    public function itFailsAndRecordsExactlyOneResourcelessSecurityEntryPerRun(): void
    {
        $auditLogger = new RecordingAuditLogger();
        $tester = $this->tester(new FixedStoredIdentityIntegrity(['GHOST_ROLE', 'RETIRED'], 3), $auditLogger);

        $tester->execute([]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('GHOST_ROLE', $tester->getDisplay());
        // One row for five drifted facts across two columns: the cardinality is per RUN, never per finding
        // and never per identity — the refusals themselves are re-evaluated on every authenticated request.
        $this->assertCount(1, $auditLogger->records);
        $this->assertSame(AuditLevel::SECURITY, $auditLogger->records[0]['level']);
        // Resource-less: the drifted identities are exactly the person ids this axis would then owe an
        // erasure, and the finding is actionable without any of them.
        $this->assertNotInstanceOf(AuditResource::class, $auditLogger->records[0]['resource']);
        $this->assertSame(
            [
                'orphan_role_values' => ['GHOST_ROLE', 'RETIRED'],
                'malformed_roles' => 0,
                'unreadable_credentials' => 3,
                'admitted_without_credential' => 0,
            ],
            $auditLogger->records[0]['metadata'],
        );
    }

    #[Test]
    public function itReportsEachFindingUnderItsOwnColumnAndRepair(): void
    {
        $auditLogger = new RecordingAuditLogger();
        $tester = $this->tester(
            new FixedStoredIdentityIntegrity([], 0, malformedRoles: 2, admittedWithoutCredential: 5),
            $auditLogger,
        );

        $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        // Each fault reported under its own heading with its own repair: they are found by different reads and
        // fixed by different acts, and a merged "something is wrong with identity_user" sends an operator to
        // the wrong query. The counts are what say WHICH, since no identity is ever named.
        $this->assertStringContainsString('not a JSON array', $display);
        $this->assertStringContainsString('2 identity(ies)', $display);
        $this->assertStringContainsString('admitted identities holding no credential', $display);
        $this->assertStringContainsString('5 identity(ies)', $display);
        // Still ONE row, for two faults across seven identities.
        $this->assertCount(1, $auditLogger->records);
    }

    #[Test]
    public function itNamesNoIdentityInAnyReport(): void
    {
        // The values are stored text and are printed; the identities are counted and are not. That asymmetry
        // is the contract — an identity id is a person reference and this output reaches job logs — and only
        // an assertion keeps it from decaying into a listing somebody adds for convenience.
        $tester = $this->tester(
            new FixedStoredIdentityIntegrity(['GHOST_ROLE'], 1, malformedRoles: 1, admittedWithoutCredential: 1),
            new RecordingAuditLogger(),
        );

        $tester->execute([]);

        $this->assertMatchesRegularExpression('/GHOST_ROLE/', $tester->getDisplay());
        $this->assertDoesNotMatchRegularExpression(
            '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i',
            $tester->getDisplay(),
        );
    }

    #[Test]
    public function itReachesNoVerdictWhenAProbeFails(): void
    {
        $auditLogger = new RecordingAuditLogger();
        $tester = $this->tester(new FailingStoredIdentityIntegrity(), $auditLogger);

        $tester->execute([]);

        // NOT `FAILURE`: that code sends someone to repair rows, and nothing was established about any row.
        $this->assertSame(Command::INVALID, $tester->getStatusCode());
        $this->assertStringContainsString('NOT a finding', $tester->getDisplay());
        // Nor may a run that established nothing leave a finding in the trail behind it.
        $this->assertSame([], $auditLogger->records);
    }

    #[Test]
    public function itStillReportsTheFindingWhenTheTrailWriteFails(): void
    {
        $tester = $this->tester(new FixedStoredIdentityIntegrity(['GHOST_ROLE'], 0), new FailingAuditLogger());

        $tester->execute([]);

        // The finding stands and reaches the operator; only its trail entry did not. Answering SUCCESS here
        // would hide a real drift behind an unrelated audit fault.
        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('GHOST_ROLE', $tester->getDisplay());
        $this->assertStringContainsString('could not be recorded in the audit trail', $tester->getDisplay());
    }

    private function tester(StoredIdentityIntegrity $integrity, AuditLogger $auditLogger): CommandTester
    {
        return new CommandTester(new InspectStoredIdentityIntegrityCommand($integrity, $auditLogger));
    }
}
