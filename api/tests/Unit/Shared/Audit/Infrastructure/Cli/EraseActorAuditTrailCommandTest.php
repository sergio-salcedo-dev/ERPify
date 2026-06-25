<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Audit\Infrastructure\Cli;

use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Infrastructure\Cli\EraseActorAuditTrailCommand;
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

    private function testerFor(RecordingAuditActorAnonymiser $anonymiser, RecordingAuditLogger $logger): CommandTester
    {
        return new CommandTester(new EraseActorAuditTrailCommand($anonymiser, $logger));
    }
}
