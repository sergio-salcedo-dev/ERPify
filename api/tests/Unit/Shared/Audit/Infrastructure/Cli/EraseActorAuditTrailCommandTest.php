<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Audit\Infrastructure\Cli;

use Erpify\Shared\Audit\Application\AuditLogger;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Infrastructure\Cli\EraseActorAuditTrailCommand;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\FailingAuditLogger;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\RecordingAuditActorAnonymiser;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\RecordingAuditLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @internal
 */
#[CoversClass(EraseActorAuditTrailCommand::class)]
final class EraseActorAuditTrailCommandTest extends TestCase
{
    private const string ACTOR_ID = '550e8400-e29b-41d4-a716-446655440000';

    public function testItRejectsAMalformedActorIdBeforeTouchingTheDatabase(): void
    {
        $anonymiser = new RecordingAuditActorAnonymiser(0);
        $logger = new RecordingAuditLogger();
        $tester = $this->testerFor($anonymiser, $logger);

        $exitCode = $tester->execute(['actor-id' => 'not-a-uuid']);

        $this->assertSame(Command::INVALID, $exitCode);
        $this->assertSame(0, $anonymiser->countForCalls, 'a malformed id never reaches the database');
        $this->assertCount(0, $anonymiser->anonymisedActorIds);
        $this->assertCount(0, $logger->records);
    }

    public function testDryRunReportsTheCountWithoutMutatingOrAuditing(): void
    {
        $anonymiser = new RecordingAuditActorAnonymiser(3);
        $logger = new RecordingAuditLogger();
        $tester = $this->testerFor($anonymiser, $logger);

        $exitCode = $tester->execute(['actor-id' => self::ACTOR_ID, '--dry-run' => true]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Rows matched: 3', $tester->getDisplay());
        $this->assertCount(0, $anonymiser->anonymisedActorIds, 'dry run never mutates');
        $this->assertCount(0, $logger->records, 'dry run never audits');
    }

    public function testItDoesNothingWhenNoRowsMatch(): void
    {
        $anonymiser = new RecordingAuditActorAnonymiser(0);
        $logger = new RecordingAuditLogger();
        $tester = $this->testerFor($anonymiser, $logger);

        $exitCode = $tester->execute(['actor-id' => self::ACTOR_ID]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertCount(0, $anonymiser->anonymisedActorIds);
        $this->assertCount(0, $logger->records);
    }

    public function testDecliningTheConfirmationLeavesTheTrailIntact(): void
    {
        $anonymiser = new RecordingAuditActorAnonymiser(5);
        $logger = new RecordingAuditLogger();
        $tester = $this->testerFor($anonymiser, $logger);
        $tester->setInputs(['no']);

        $exitCode = $tester->execute(['actor-id' => self::ACTOR_ID]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertCount(0, $anonymiser->anonymisedActorIds);
        $this->assertCount(0, $logger->records);
    }

    /**
     * The operator was asked and never answered, so success would be a lie a compliance job cannot see
     * through: it reads `$?`, and a `0` from a trail nobody anonymised is indistinguishable from a `0` from
     * one that was.
     */
    public function testAnUnattendedRunWithoutForceRefusesInsteadOfReportingSuccess(): void
    {
        $anonymiser = new RecordingAuditActorAnonymiser(5);
        $logger = new RecordingAuditLogger();
        $tester = $this->testerFor($anonymiser, $logger);

        $exitCode = $tester->execute(['actor-id' => self::ACTOR_ID], ['interactive' => false]);

        $this->assertSame(Command::INVALID, $exitCode);
        // Single tokens: the refusal renders as a SymfonyStyle error block, which word-wraps to the terminal
        // width, so any multi-word phrase can straddle a line break the assertion cannot see.
        $this->assertStringContainsString('Refusing', $tester->getDisplay());
        $this->assertStringContainsString('--force', $tester->getDisplay());
        $this->assertCount(0, $anonymiser->anonymisedActorIds);
        $this->assertCount(0, $logger->records);
    }

    /**
     * A separate path from --no-interaction: the input is still interactive when the question is put, and the
     * question helper answers it with the default rather than raising.
     */
    public function testAConfirmationNobodyCanAnswerRefusesInsteadOfReportingSuccess(): void
    {
        $anonymiser = new RecordingAuditActorAnonymiser(5);
        $logger = new RecordingAuditLogger();
        $tester = $this->testerFor($anonymiser, $logger);
        $tester->setInputs([]);

        $exitCode = $tester->execute(['actor-id' => self::ACTOR_ID]);

        $this->assertSame(Command::INVALID, $exitCode);
        $this->assertStringContainsString('Refusing', $tester->getDisplay());
        $this->assertCount(0, $anonymiser->anonymisedActorIds);
        $this->assertCount(0, $logger->records);
    }

    /**
     * Both no-ops the operator did express keep their exit code even where no confirmation could be asked
     * for: they are checked before the unattended refusal for exactly that reason.
     */
    public function testAnUnattendedDryRunStaysSuccessful(): void
    {
        $anonymiser = new RecordingAuditActorAnonymiser(5);
        $logger = new RecordingAuditLogger();
        $tester = $this->testerFor($anonymiser, $logger);

        $exitCode = $tester->execute(
            ['actor-id' => self::ACTOR_ID, '--dry-run' => true],
            ['interactive' => false],
        );

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertCount(0, $anonymiser->anonymisedActorIds);
    }

    public function testAnUnattendedRunWithNoMatchingRowsStaysSuccessful(): void
    {
        $anonymiser = new RecordingAuditActorAnonymiser(0);
        $logger = new RecordingAuditLogger();
        $tester = $this->testerFor($anonymiser, $logger);

        $exitCode = $tester->execute(['actor-id' => self::ACTOR_ID], ['interactive' => false]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertCount(0, $anonymiser->anonymisedActorIds);
    }

    public function testItErasesAndAuditsTheErasureWithThePseudonymNeverTheOriginalId(): void
    {
        $anonymiser = new RecordingAuditActorAnonymiser(2);
        $logger = new RecordingAuditLogger();
        $tester = $this->testerFor($anonymiser, $logger);

        $exitCode = $tester->execute(['actor-id' => self::ACTOR_ID, '--force' => true]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertSame([self::ACTOR_ID], $anonymiser->anonymisedActorIds);
        $this->assertCount(1, $logger->records, 'a real erasure self-audits exactly once');

        $record = $logger->records[0];
        $this->assertSame('GDPR_ERASURE_EXECUTED', $record['action']);
        $this->assertSame(AuditLevel::SECURITY, $record['level']);

        $metadata = $record['metadata'];
        $this->assertArrayHasKey('anonymized_actor_id', $metadata);
        $this->assertArrayHasKey('affected_rows', $metadata);
        $this->assertSame($anonymiser->pseudonym, $metadata['anonymized_actor_id']);
        $this->assertSame(2, $metadata['affected_rows']);
        $this->assertNotSame(self::ACTOR_ID, $metadata['anonymized_actor_id'], 'the original id is never logged');
    }

    public function testItReportsFailureWhenTheSelfAuditFailsAfterTheRowsAreAnonymised(): void
    {
        $anonymiser = new RecordingAuditActorAnonymiser(2);
        $tester = $this->testerFor($anonymiser, new FailingAuditLogger());

        $exitCode = $tester->execute(['actor-id' => self::ACTOR_ID, '--force' => true]);

        $this->assertSame(Command::FAILURE, $exitCode, 'a failed self-audit after a real erasure is not a success');
        $this->assertSame([self::ACTOR_ID], $anonymiser->anonymisedActorIds, 'the rows were still anonymised');

        $display = $tester->getDisplay();
        $this->assertStringContainsString($anonymiser->pseudonym, $display, 'the operator is shown the new pseudonym');
        $this->assertStringContainsString('GDPR_ERASURE_EXECUTED', $display);
    }

    private function testerFor(RecordingAuditActorAnonymiser $anonymiser, AuditLogger $logger): CommandTester
    {
        return new CommandTester(new EraseActorAuditTrailCommand($anonymiser, $logger));
    }
}
