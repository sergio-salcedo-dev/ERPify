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
 * The exit code is the contract: whatever schedules this command reads nothing else, so the three outcomes
 * have to stay distinguishable. `INVALID` is the one no scenario can exercise — a probe fails when the
 * database cannot answer, which a run cannot arrange without breaking the suite carrying it — and it is also
 * the one that matters most, because a failed read reported as `SUCCESS` is a control certifying a table it
 * never managed to look at.
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
            ['orphan_role_values' => ['GHOST_ROLE', 'RETIRED'], 'unreadable_credentials' => 3],
            $auditLogger->records[0]['metadata'],
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
