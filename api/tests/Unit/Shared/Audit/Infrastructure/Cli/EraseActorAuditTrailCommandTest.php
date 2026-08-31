<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Audit\Infrastructure\Cli;

use Erpify\Shared\Audit\Application\AuditActorAnonymiser;
use Erpify\Shared\Audit\Application\AuditLogger;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Infrastructure\Cli\EraseActorAuditTrailCommand;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\FailingAuditLogger;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\RecordingAuditActorAnonymiser;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\RecordingAuditLogger;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\ThrowingAuditActorAnonymiser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
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

    /**
     * The operator is asked even when the trail holds nothing, because a question conditional on the subject
     * hands a run that cannot answer it an exit code describing that subject. What an affirmative answer buys
     * here is the report and not the `UPDATE`: a statement that can match nothing is not an erasure.
     */
    public function testItDoesNothingWhenNoRowsMatch(): void
    {
        $anonymiser = new RecordingAuditActorAnonymiser(0);
        $logger = new RecordingAuditLogger();
        $tester = $this->testerFor($anonymiser, $logger);
        $tester->setInputs(['yes']);

        $exitCode = $tester->execute(['actor-id' => self::ACTOR_ID]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Irreversibly anonymise 0 row(s)?', $tester->getDisplay());
        $this->assertStringContainsString('nothing to erase', $tester->getDisplay());
        $this->assertCount(
            0,
            $anonymiser->anonymisedActorIds,
            'an affirmative answer over an empty trail reaches no UPDATE',
        );
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
        $this->assertSame(0, $anonymiser->countForCalls, '--force needs no preview; affectedRows is authoritative');
    }

    public function testItReportsFailureWhenTheSelfAuditFailsAfterTheRowsAreAnonymised(): void
    {
        $anonymiser = new RecordingAuditActorAnonymiser(2);
        $tester = $this->testerFor($anonymiser, new FailingAuditLogger());

        $exitCode = $tester->execute(['actor-id' => self::ACTOR_ID, '--force' => true]);

        $this->assertSame(
            EraseActorAuditTrailCommand::ERASED_UNRECORDED,
            $exitCode,
            'an erasure that committed without its compliance entry is neither a success nor an ordinary failure',
        );
        // Comparing two literal constants is a tautology PHPStan rejects outright, so the claim that matters
        // — the code sits OUTSIDE the vocabulary a caller already knows — is derived from `Command` instead
        // of restated. Setting `ERASED_UNRECORDED` to any of the three reds this line.
        $this->assertNotContains(
            EraseActorAuditTrailCommand::ERASED_UNRECORDED,
            (new ReflectionClass(Command::class))->getConstants(),
            'the erased-but-unrecorded outcome must not collide with a code a caller already reads',
        );
        $this->assertSame([self::ACTOR_ID], $anonymiser->anonymisedActorIds, 'the rows were still anonymised');

        $display = $tester->getDisplay();
        $this->assertStringContainsString($anonymiser->pseudonym, $display, 'the operator is shown the new pseudonym');
        $this->assertStringContainsString('GDPR_ERASURE_EXECUTED', $display);
    }

    /**
     * `--force` takes no preview, so this is the one path that can reach the `UPDATE` for an actor whose rows
     * are already gone. The self-audit must not fire: `AuditErasureEvidence` exempts this action from the
     * retention prune for ever, so a row written here is an immortal claim that an erasure happened when the
     * statement matched nothing.
     */
    public function testAForcedRunOverAnAlreadyErasedActorAuditsNothing(): void
    {
        $anonymiser = new RecordingAuditActorAnonymiser(5, affectedRows: 0);
        $logger = new RecordingAuditLogger();
        $tester = $this->testerFor($anonymiser, $logger);

        $exitCode = $tester->execute(['actor-id' => self::ACTOR_ID, '--force' => true]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertSame([self::ACTOR_ID], $anonymiser->anonymisedActorIds, 'the statement did run');
        $this->assertCount(0, $logger->records, 'an UPDATE that matched nothing is not evidence of an erasure');
    }

    /**
     * A trail that cannot be counted is `FAILURE` and not `INVALID`: a database that cannot answer is exactly
     * what a caller should retry, and `INVALID` is the code this command's contract tells it never to retry
     * on. Both reading paths are covered, because they call the count from different branches.
     */
    #[DataProvider('provideAFailedRowCountIsReportedAsAFailureCases')]
    public function testAFailedRowCountIsReportedAsAFailure(bool $dryRun): void
    {
        $logger = new RecordingAuditLogger();
        $tester = $this->testerFor(ThrowingAuditActorAnonymiser::onCount(), $logger);

        $arguments = ['actor-id' => self::ACTOR_ID];

        if ($dryRun) {
            $arguments['--dry-run'] = true;
        }

        $exitCode = $tester->execute($arguments);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('Could not read', $tester->getDisplay());
        $this->assertCount(0, $logger->records);
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function provideAFailedRowCountIsReportedAsAFailureCases(): iterable
    {
        yield 'the confirmation preview' => [false];
        yield 'the dry run' => [true];
    }

    /**
     * The `UPDATE` failing is the one outcome the exit code cannot describe on its own: a connection lost
     * mid-statement can commit without acknowledging, so the message must not promise the rows are untouched.
     */
    public function testAFailedAnonymisationIsReportedAsAFailure(): void
    {
        $logger = new RecordingAuditLogger();
        $tester = $this->testerFor(ThrowingAuditActorAnonymiser::onAnonymise(), $logger);

        $exitCode = $tester->execute(['actor-id' => self::ACTOR_ID, '--force' => true]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('Anonymisation failed', $tester->getDisplay());
        $this->assertStringContainsString('--dry-run', $tester->getDisplay(), 'the operator is told how to verify');
        $this->assertCount(0, $logger->records, 'an erasure that did not complete is not self-audited');
    }

    private function testerFor(AuditActorAnonymiser $anonymiser, AuditLogger $logger): CommandTester
    {
        return new CommandTester(new EraseActorAuditTrailCommand($anonymiser, $logger));
    }
}
