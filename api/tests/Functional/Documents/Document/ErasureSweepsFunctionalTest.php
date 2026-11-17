<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Documents\Document;

use DateTimeImmutable;
use Erpify\Documents\Document\Application\Erasure\DeleteErasedObjects;
use Erpify\Documents\Document\Application\Erasure\EraseDocument;
use Erpify\Documents\Document\Application\Erasure\ErasureLedger;
use Erpify\Documents\Document\Application\Erasure\ErasureOverrideSwitch;
use Erpify\Documents\Document\Application\Erasure\ErasureProgress;
use Erpify\Documents\Document\Application\Erasure\ReplayErasure;
use Erpify\Documents\Document\Application\Erasure\StandingOverride;
use Erpify\Documents\Document\Application\Read\ErasureLedgerOverride;
use Erpify\Documents\Document\Domain\DocumentState;
use Erpify\Documents\Document\Domain\Erasure\DocumentErasures;
use Erpify\Documents\Document\Domain\Erasure\ErasureLimits;
use Erpify\Documents\Document\Domain\Erasure\ErasureReason;
use Erpify\Documents\Document\Domain\Erasure\ErasureSystemReason;
use Erpify\Documents\Document\Domain\Erasure\LedgerOverrideReason;
use Erpify\Documents\Document\Domain\Storage\DocumentStorage;
use Erpify\Documents\Document\Domain\Storage\StorageArea;
use Erpify\Documents\Document\Infrastructure\Cli\ClearErasureLedgerOverrideCommand;
use Erpify\Documents\Document\Infrastructure\Messenger\Maintenance\CompleteInterruptedErasuresHandler;
use Erpify\Documents\Document\Infrastructure\Messenger\Maintenance\CompleteInterruptedErasuresMessage;
use Erpify\Documents\Document\Infrastructure\Messenger\Maintenance\ReportTombstonedDocumentsHandler;
use Erpify\Documents\Document\Infrastructure\Messenger\Maintenance\ReportTombstonedDocumentsMessage;
use Erpify\Shared\Clock\Domain\SystemClock;
use Erpify\Shared\Crypto\Domain\EncryptionScopeId;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Double\Erasure\FakeErasureLedger;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Uid\Uuid as RandomUuid;

/**
 * The erasure's three sweeps, the restore's replay and the ledger override, against Postgres, the real keystore and the
 * real document volume: an entry that landed with no transaction behind it is finished only once it is old enough, an
 * unreadable ledger raises one alarm and changes nothing, the replay erases what a restore brought back, the object
 * sweep deletes staged then final and marks the row done, and the override clears only after a clean replay.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 * @SuppressWarnings("PHPMD.ExcessiveClassLength") one test per sweep, command and ending
 * @SuppressWarnings("PHPMD.TooManyMethods") the same
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 */
#[CoversClass(CompleteInterruptedErasuresHandler::class)]
#[CoversClass(ReportTombstonedDocumentsHandler::class)]
#[CoversClass(DeleteErasedObjects::class)]
#[CoversClass(ReplayErasure::class)]
final class ErasureSweepsFunctionalTest extends KernelTestCase
{
    use PlantsNamedDocuments;

    private TestHandler $log;

    private Logger $logger;

    /** @var list<string> */
    private array $scopes = [];

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();
        FakeErasureLedger::reset();
        $this->emptyDocumentTables();
        $this->connection()->executeStatement('TRUNCATE erasure_ledger_override');
        $this->log = new TestHandler();
        $this->logger = new Logger('test', [$this->log]);
    }

    protected function tearDown(): void
    {
        foreach ($this->scopes as $scope) {
            $this->storage()->delete(StorageArea::Staging, $scope);
            $this->storage()->delete(StorageArea::Final, $scope);
        }

        FakeErasureLedger::reset();
        $this->connection()->executeStatement('TRUNCATE erasure_ledger_override');
        $this->emptyDocumentTables();
        parent::tearDown();
    }

    #[Test]
    public function anEntryThatLandedWithNoTransactionIsFinishedBySystemOnceItIsOldEnough(): void
    {
        $document = $this->plantNamedDocument('plan.pdf');
        FakeErasureLedger::plant($document['scope'], $this->ago(ErasureLimits::COMPLETION_MIN_AGE_SECONDS + 5));

        $this->completion()(new CompleteInterruptedErasuresMessage());

        $this->assertSame('erased', $this->stateOf($document['id']));
        $this->assertNull($this->connection()->fetchOne(
            'SELECT filename_ciphertext FROM document WHERE id = CAST(:id AS UUID)',
            ['id' => $document['id']],
        ) ?: null);
        $this->assertSame(
            [['erased', null, 'ledger-completion']],
            $this->custodyOf($document['id']),
            'the system finished it: no actor, and the reason says which sweep',
        );
        $this->assertNotNull($this->tombstoneReason($document['scope']));
    }

    #[Test]
    public function anEntryYoungerThanTheMarginIsLeftToTheRequestThatWroteIt(): void
    {
        $document = $this->plantNamedDocument('plan.pdf');
        FakeErasureLedger::plant($document['scope'], $this->ago(ErasureLimits::COMPLETION_MIN_AGE_SECONDS - 20));

        $this->completion()(new CompleteInterruptedErasuresMessage());

        $this->assertSame('ready', $this->stateOf($document['id']));
        $this->assertSame([], $this->custodyOf($document['id']));
        $this->assertNull($this->tombstoneReason($document['scope']));
    }

    #[Test]
    public function aLedgerThatCannotBeReadRaisesOneAlarmAndChangesNothing(): void
    {
        $document = $this->plantNamedDocument('plan.pdf');
        FakeErasureLedger::plant($document['scope'], $this->ago(3_600));
        FakeErasureLedger::makeUnreadable();

        $this->completion()(new CompleteInterruptedErasuresMessage());

        $this->assertSame('ready', $this->stateOf($document['id']));
        $this->assertCount(1, $this->log->getRecords());
        $this->assertTrue($this->log->hasRecordThatContains('cannot be read in full', Level::Error));
    }

    #[Test]
    public function aDuplicateTickFindsNothingLeftToDo(): void
    {
        $document = $this->plantNamedDocument('plan.pdf');
        FakeErasureLedger::plant($document['scope'], $this->ago(3_600));
        $tick = $this->completion();

        $tick(new CompleteInterruptedErasuresMessage());
        $tick(new CompleteInterruptedErasuresMessage());

        $this->assertCount(1, $this->custodyOf($document['id']));
    }

    #[Test]
    public function theReplayErasesWhatARestoreBroughtBackAndDeletesTheObjectsOfAScopeItNeverHeld(): void
    {
        $restored = $this->plantNamedDocument('plan.pdf');
        $unknownScope = RandomUuid::v4()->toRfc4122();
        $this->scopes[] = $restored['scope'];
        $this->scopes[] = $unknownScope;
        $this->putObject($unknownScope, StorageArea::Final);
        $this->putObject($unknownScope, StorageArea::Staging);
        FakeErasureLedger::plant($restored['scope']);
        FakeErasureLedger::plant($unknownScope);

        $tester = $this->command('documents:erasure:replay-ledger');
        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertSame('erased', $this->stateOf($restored['id']));
        $this->assertSame([['erased', null, 'ledger-replay']], $this->custodyOf($restored['id']));
        $this->assertNotNull($this->tombstoneReason($restored['scope']));
        $this->assertNotNull(
            $this->tombstoneReason($unknownScope),
            'a scope this database never held still gets its tombstone',
        );
        $this->assertFalse($this->storage()->has(StorageArea::Staging, $unknownScope));
        $this->assertFalse($this->storage()->has(StorageArea::Final, $unknownScope));
    }

    #[Test]
    public function theReplayRefusesAnUnreadableLedgerAndTouchesNothing(): void
    {
        $document = $this->plantNamedDocument('plan.pdf');
        FakeErasureLedger::plant($document['scope']);
        FakeErasureLedger::makeUnreadable();

        $tester = $this->command('documents:erasure:replay-ledger');
        $tester->execute([]);

        $this->assertNotSame(0, $tester->getStatusCode());
        $this->assertSame('ready', $this->stateOf($document['id']));
        $this->assertNull($this->tombstoneReason($document['scope']));
    }

    #[Test]
    public function theObjectSweepDeletesStagedThenFinalAndMarksTheRowDone(): void
    {
        $document = $this->plantErasedDocument();
        $this->putObject($document['scope'], StorageArea::Final);
        $this->putObject($document['scope'], StorageArea::Staging);

        $done = $this->objectSweep()->run(10);

        $this->assertSame(1, $done);
        $this->assertFalse($this->storage()->has(StorageArea::Staging, $document['scope']));
        $this->assertFalse($this->storage()->has(StorageArea::Final, $document['scope']));
        $this->assertNotNull($this->connection()->fetchOne(
            'SELECT object_deleted_at FROM document WHERE id = CAST(:id AS UUID)',
            ['id' => $document['id']],
        ));
        $this->assertSame(0, $this->objectSweep()->run(10), 'a done row is not probed again');
    }

    #[Test]
    public function aFailedDeleteLeavesTheRowPendingAndTheAlarmNamesItOnlyPastTheBound(): void
    {
        $document = $this->plantErasedDocument(erasedSecondsAgo: ErasureLimits::OBJECT_ALARM_SECONDS + 60);
        $this->putObject($document['scope'], StorageArea::Final);
        $failing = (new ScriptedDocumentStorage($this->storage()))->failEveryDelete();

        $done = (new DeleteErasedObjects($this->service(DocumentErasures::class), $failing, $this->logger))->run(10);

        $this->assertSame(0, $done);
        $this->assertTrue($this->storage()->has(StorageArea::Final, $document['scope']), 'the object is still there');
        $this->assertSame('erased', $this->stateOf($document['id']), 'a failed delete never rolls the erasure back');
        $this->assertTrue($this->log->hasRecordThatContains('older than the bound', Level::Error));
    }

    #[Test]
    public function aYoungErasureWithItsObjectStillThereRaisesNoAlarm(): void
    {
        $document = $this->plantErasedDocument(erasedSecondsAgo: 30);
        $this->putObject($document['scope'], StorageArea::Final);
        $failing = (new ScriptedDocumentStorage($this->storage()))->failEveryDelete();

        (new DeleteErasedObjects($this->service(DocumentErasures::class), $failing, $this->logger))->run(10);

        $this->assertFalse($this->log->hasRecordThatContains('older than the bound', Level::Error));
    }

    #[Test]
    public function theReconcilerAlarmsOnARowThatIsNotErasedWhoseKeyIsDestroyedWhateverTheLedgerSays(): void
    {
        $stranded = $this->plantNamedDocument('plan.pdf');
        $this->destroyScopeOf($stranded['scope']);
        $sound = $this->plantNamedDocument('plan.pdf');
        $erased = $this->plantErasedDocument();
        $this->destroyScopeOf($erased['scope']);
        FakeErasureLedger::plant($stranded['scope']);

        $this->reconciler()(new ReportTombstonedDocumentsMessage());

        $records = $this->log->getRecords();
        $this->assertCount(1, $records);
        $record = \array_first($records);
        $this->assertSame(Level::Error, $record->level);
        $this->assertSame([$stranded['id']], $record->context['documentIds'] ?? null);
        $this->assertNotSame($sound['id'], $stranded['id']);
    }

    #[Test]
    public function theReconcilerIsSilentWhenEveryDestroyedKeyBelongsToAnErasedRow(): void
    {
        $erased = $this->plantErasedDocument();
        $this->destroyScopeOf($erased['scope']);

        $this->reconciler()(new ReportTombstonedDocumentsMessage());

        $this->assertSame([], $this->log->getRecords());
    }

    #[Test]
    public function theCompletionTickNeverClearsAnOverrideAndRaisesOneErrorLineWhileItStands(): void
    {
        $switch = $this->service(ErasureOverrideSwitch::class);
        $override = $this->service(ErasureLedgerOverride::class);
        $this->assertFalse($override->isActive());
        $this->assertNotInstanceOf(StandingOverride::class, $switch->standing());

        $ref = $switch->activate(LedgerOverrideReason::LedgerUnreachable);
        $this->assertTrue(Uuid::isValid($ref));
        $this->connection()->executeStatement(
            "UPDATE erasure_ledger_override SET set_at = NOW() - INTERVAL '2 hours'",
        );

        // A readable ledger, a clean database and every entry old: every precondition the clearing command checks
        // holds, and still the tick clears nothing, for any reason.
        $document = $this->plantNamedDocument('plan.pdf');
        FakeErasureLedger::plant($document['scope'], $this->ago(3_600));
        $this->completion()(new CompleteInterruptedErasuresMessage());
        $this->completion()(new CompleteInterruptedErasuresMessage());

        $this->assertSame('erased', $this->stateOf($document['id']), 'the completion itself still runs');
        $this->assertTrue($override->isActive(), 'the tick clears nothing');
        $standing = \array_values(\array_filter(
            $this->log->getRecords(),
            static fn (LogRecord $record): bool => \str_contains($record->message, 'override is still set'),
        ));
        $this->assertCount(2, $standing, 'one line per tick');
        $this->assertSame(Level::Error, $standing[0]->level);
        $this->assertSame(1, $standing[0]->context['count'] ?? null);
        $this->assertGreaterThanOrEqual(7_200, $standing[0]->context['oldestAgeSeconds'] ?? 0);
        $this->assertSame([], \array_diff(\array_keys($standing[0]->context), ['count', 'oldestAgeSeconds']));
    }

    #[Test]
    public function noReasonClearsAnOverrideByItself(): void
    {
        foreach (LedgerOverrideReason::cases() as $reason) {
            $this->service(ErasureOverrideSwitch::class)->activate($reason);
        }

        $this->completion()(new CompleteInterruptedErasuresMessage());

        $this->assertCount(
            \count(LedgerOverrideReason::cases()),
            $this->service(ErasureOverrideSwitch::class)->unclearedIds(),
        );
    }

    #[Test]
    public function theClearCommandRefusesAnUnreadableLedgerAndClearsNothing(): void
    {
        $this->service(ErasureOverrideSwitch::class)->activate(LedgerOverrideReason::LedgerCorrupt);
        FakeErasureLedger::makeUnreadable();

        $tester = $this->command('documents:erasure:clear-override');
        $tester->execute([]);

        $this->assertNotSame(0, $tester->getStatusCode());
        $this->assertTrue($this->service(ErasureLedgerOverride::class)->isActive());
    }

    #[Test]
    public function theClearCommandRefusesWhileARowOfTheLedgerIsNotErased(): void
    {
        $this->service(ErasureOverrideSwitch::class)->activate(LedgerOverrideReason::LedgerLost);
        $document = $this->plantNamedDocument('plan.pdf');
        FakeErasureLedger::plant($document['scope']);

        $tester = $this->command('documents:erasure:clear-override');
        $tester->execute([]);

        $this->assertNotSame(0, $tester->getStatusCode());
        $this->assertSame('ready', $this->stateOf($document['id']), 'it verifies and replays nothing itself');
        $this->assertTrue($this->service(ErasureLedgerOverride::class)->isActive());
    }

    #[Test]
    public function theClearCommandClearsOnlyTheOverridesItSawBeforeItReadTheLedger(): void
    {
        $switch = $this->service(ErasureOverrideSwitch::class);
        $seenRef = $switch->activate(LedgerOverrideReason::LedgerLost);
        $document = $this->plantNamedDocument('plan.pdf');
        $this->service(EraseDocument::class)->erase(
            $document['id'],
            ErasureReason::DataSubjectRequest,
            Uuid::generate(),
        );
        $racing = new RacingErasureLedger($this->service(ErasureLedger::class), $switch);

        $tester = new CommandTester(new ClearErasureLedgerOverrideCommand(
            $switch,
            $racing,
            $this->service(ErasureProgress::class),
        ));
        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertStringContainsString($seenRef, $tester->getDisplay(), 'the cleared reference is printed');
        $this->assertNotNull($racing->lateReference);
        $this->assertStringNotContainsString($racing->lateReference, $tester->getDisplay());
        $this->assertCount(1, $switch->unclearedIds(), 'the override set after the snapshot is still standing');
        $this->assertTrue($this->service(ErasureLedgerOverride::class)->isActive());
    }

    #[Test]
    public function theClearCommandWithNothingSetSaysSoAndSucceeds(): void
    {
        $tester = $this->command('documents:erasure:clear-override');
        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
    }

    #[Test]
    public function theSetOverrideCommandRecordsOneRowForAValidReasonAndNoneForAnInvalidOne(): void
    {
        $tester = $this->command('documents:erasure:set-override');
        $tester->execute(['reason' => 'ledger_lost']);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame(1, $this->rowsIn('erasure_ledger_override'));
        $this->assertMatchesRegularExpression(
            '/[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}/',
            $tester->getDisplay(),
        );
        $this->assertTrue($this->service(ErasureLedgerOverride::class)->isActive());

        $this->connection()->executeStatement('TRUNCATE erasure_ledger_override');
        $invalid = $this->command('documents:erasure:set-override');
        $invalid->execute(['reason' => 'because']);

        $this->assertSame(Command::INVALID, $invalid->getStatusCode());
        $this->assertSame(0, $this->rowsIn('erasure_ledger_override'));
    }

    #[Test]
    public function theCheckLedgerCommandFailsOnAnUnreadableLedgerAndPassesOnAReadableOne(): void
    {
        $tester = $this->command('documents:erasure:check-ledger');
        $tester->execute([]);
        $this->assertSame(0, $tester->getStatusCode());

        FakeErasureLedger::makeUnreadable();
        $tester->execute([]);

        $this->assertNotSame(0, $tester->getStatusCode());
    }

    #[Test]
    public function aRestoreReplayReopensTheMarkerOfAnAlreadyErasedRowSoTheSweepDeletesTheObjectThatCameBack(): void
    {
        $document = $this->plantErasedDocument();
        $this->connection()->executeStatement(
            'UPDATE document SET object_deleted_at = NOW() WHERE id = CAST(:id AS UUID)',
            ['id' => $document['id']],
        );
        $this->putObject($document['scope'], StorageArea::Final);
        FakeErasureLedger::plant($document['scope']);
        $this->assertSame(0, $this->objectSweep()->run(10), 'marked done, so the sweep never looks at it');

        $tester = $this->command('documents:erasure:replay-ledger');
        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertNull($this->connection()->fetchOne(
            'SELECT object_deleted_at FROM document WHERE id = CAST(:id AS UUID)',
            ['id' => $document['id']],
        ) ?: null, 'the same transaction reopened it');
        $this->assertTrue($this->storage()->has(StorageArea::Final, $document['scope']));

        $this->assertSame(1, $this->objectSweep()->run(10));
        $this->assertFalse($this->storage()->has(StorageArea::Final, $document['scope']));
    }

    #[Test]
    public function aScopeWithNoRowHasItsObjectsDeletedByTheRestoreReplayAndLeftAloneByTheCompletionSweep(): void
    {
        $replayed = RandomUuid::v4()->toRfc4122();
        $completed = RandomUuid::v4()->toRfc4122();
        $this->scopes[] = $replayed;
        $this->scopes[] = $completed;

        foreach ([$replayed, $completed] as $scope) {
            $this->putObject($scope, StorageArea::Final);
            $this->putObject($scope, StorageArea::Staging);
        }

        $replay = $this->service(ReplayErasure::class);
        $replay->replay($replayed, ErasureSystemReason::LedgerReplay);
        $replay->replay($completed, ErasureSystemReason::LedgerCompletion);

        // The first half is the positive control: it proves this test can see a deletion, so the second half is not
        // green merely because nothing here ever deletes. Removing the reason guard in `ReplayErasure` reddens it.
        $this->assertFalse($this->storage()->has(StorageArea::Final, $replayed));
        $this->assertFalse($this->storage()->has(StorageArea::Staging, $replayed));
        $this->assertTrue($this->storage()->has(StorageArea::Final, $completed), 'a completion deletes no object');
        $this->assertTrue($this->storage()->has(StorageArea::Staging, $completed));
        $this->assertNotNull($this->tombstoneReason($completed), 'but the scope is still closed to a late writer');
    }

    #[Test]
    public function theObjectSweepReachesRowsBehindALimitFullOfStuckOnesAndDeletesStagedBeforeFinal(): void
    {
        // Three rows, ordered by id as the keyset cursor reads them: the first two stay pending, the limit is two.
        $rows = [$this->plantErasedDocument(), $this->plantErasedDocument(), $this->plantErasedDocument()];
        \usort($rows, static fn (array $a, array $b): int => \strcmp($a['id'], $b['id']));
        $this->assertCount(3, $rows);
        [$firstStuck, $secondStuck, $reachable] = $rows;

        foreach ($rows as $row) {
            $this->putObject($row['scope'], StorageArea::Final);
            $this->putObject($row['scope'], StorageArea::Staging);
        }

        $storage = new ScriptedDocumentStorage($this->storage());
        $storage->failDeleteFor($firstStuck['scope'])->failDeleteFor($secondStuck['scope']);

        $done = (new DeleteErasedObjects($this->service(DocumentErasures::class), $storage, $this->logger))->run(2);

        $this->assertSame(1, $done, 'the row behind the two stuck ones was reached through the cursor');
        $this->assertSame(
            ['staging:' . $reachable['scope'], 'final:' . $reachable['scope']],
            $storage->deletes,
            'staged first, then final',
        );
        $this->assertTrue($this->storage()->has(StorageArea::Final, $firstStuck['scope']));
    }

    #[Test]
    public function aValueThatIsNotAUuidNeverBreaksTheProgressReadForTheScopesAroundIt(): void
    {
        $erased = $this->plantErasedDocument();
        $this->destroyScopeOf($erased['scope']);
        $progress = $this->service(ErasureProgress::class);
        $dash = \str_repeat('-', 36);

        $this->assertSame(['not-a-uuid', $dash], $progress->unfinished(['not-a-uuid', $erased['scope'], $dash]));
        $this->assertSame(0, $progress->notErasedAmong(['not-a-uuid', $erased['scope'], 'x"y\z']));
    }

    #[Test]
    public function theOverrideRecordHoldsNoNameLabelOrOperatorColumn(): void
    {
        $columns = $this->connection()->fetchFirstColumn(
            'SELECT column_name FROM information_schema.columns '
            . "WHERE table_name = 'erasure_ledger_override' ORDER BY column_name",
        );

        $this->assertSame(['cleared_at', 'id', 'reason', 'record_ref', 'set_at'], $columns);
    }

    private function completion(): CompleteInterruptedErasuresHandler
    {
        return new CompleteInterruptedErasuresHandler(
            $this->service(ErasureLedger::class),
            $this->service(ErasureProgress::class),
            $this->service(ReplayErasure::class),
            $this->service(ErasureOverrideSwitch::class),
            $this->logger,
        );
    }

    private function reconciler(): ReportTombstonedDocumentsHandler
    {
        return new ReportTombstonedDocumentsHandler($this->connection(), $this->logger);
    }

    private function objectSweep(): DeleteErasedObjects
    {
        return new DeleteErasedObjects($this->service(DocumentErasures::class), $this->storage(), $this->logger);
    }

    private function command(string $name): CommandTester
    {
        $kernel = self::$kernel;
        $this->assertInstanceOf(KernelInterface::class, $kernel);
        $application = new Application($kernel);

        return new CommandTester($application->find($name));
    }

    private function ago(int $seconds): DateTimeImmutable
    {
        return SystemClock::now()->modify('-' . $seconds . ' seconds');
    }

    /**
     * An `Erased` row with its custody row, `$erasedSecondsAgo` old, as the transaction leaves it.
     *
     * @return array{id: string, scope: string}
     */
    private function plantErasedDocument(int $erasedSecondsAgo = 600): array
    {
        $document = $this->plantNamedDocument(null, DocumentState::Erased);
        $this->scopes[] = $document['scope'];
        $this->connection()->executeStatement(
            'INSERT INTO document_custody (id, document_id, seq, event, actor_id, reason, created_at) '
            . 'VALUES (:id, :document, 1, :event, :actor, :reason, NOW() - make_interval(secs => :age))',
            [
                'id' => Uuid::generate(),
                'document' => $document['id'],
                'event' => 'erased',
                'actor' => Uuid::generate(),
                'reason' => 'data_subject_request',
                'age' => $erasedSecondsAgo,
            ],
        );

        return $document;
    }

    private function putObject(string $scope, StorageArea $area): void
    {
        $this->storage()->stageWith($scope, static function ($sink): void {
            \fwrite($sink, 'sealed');
        });

        if (StorageArea::Final === $area) {
            $this->storage()->promote($scope);
        }
    }

    private function storage(): DocumentStorage
    {
        return $this->service(DocumentStorage::class);
    }

    private function stateOf(string $id): string
    {
        $state = $this->connection()->fetchOne(
            'SELECT state FROM document WHERE id = CAST(:id AS UUID)',
            ['id' => $id],
        );
        $this->assertIsString($state);

        return $state;
    }

    /**
     * @return list<array{string, ?string, ?string}>
     */
    private function custodyOf(string $documentId): array
    {
        $rows = $this->connection()->fetchAllAssociative(
            'SELECT event, actor_id, reason FROM document_custody WHERE document_id = CAST(:id AS UUID) ORDER BY seq',
            ['id' => $documentId],
        );

        return \array_map(static fn (array $row): array => [
            \is_string($row['event'] ?? null) ? $row['event'] : '',
            \is_string($row['actor_id'] ?? null) ? $row['actor_id'] : null,
            \is_string($row['reason'] ?? null) ? $row['reason'] : null,
        ], $rows);
    }

    private function tombstoneReason(string $scope): ?string
    {
        $reason = $this->connection()->fetchOne(
            'SELECT destroy_reason FROM dek_keystore WHERE encryption_scope_id = :scope AND destroyed_at IS NOT NULL',
            ['scope' => EncryptionScopeId::forDocument($scope)->toString()],
        );

        return \is_string($reason) ? $reason : null;
    }
}
