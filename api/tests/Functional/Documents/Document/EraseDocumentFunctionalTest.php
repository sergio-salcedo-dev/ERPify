<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Documents\Document;

use Erpify\Documents\Document\Application\AppendCustody;
use Erpify\Documents\Document\Application\Erasure\ApplyErasure;
use Erpify\Documents\Document\Application\Erasure\EraseDocument;
use Erpify\Documents\Document\Application\Erasure\ErasureLedger;
use Erpify\Documents\Document\Application\Erasure\LedgerWriteFailed;
use Erpify\Documents\Document\Application\Erasure\LedgerWriteTimedOut;
use Erpify\Documents\Document\Domain\DocumentState;
use Erpify\Documents\Document\Domain\Entity\Document;
use Erpify\Documents\Document\Domain\Erasure\DocumentErasures;
use Erpify\Documents\Document\Domain\Erasure\ErasureReason;
use Erpify\Documents\Document\Domain\Exception\DocumentNotFound;
use Erpify\Documents\Document\Domain\Exception\ErasureLedgerUnavailable;
use Erpify\Documents\Document\Domain\Exception\ErasureOutcomeUnknown;
use Erpify\Documents\Document\Domain\Repository\DocumentCustodyRepository;
use Erpify\Documents\Document\Domain\Repository\DocumentRepository;
use Erpify\Documents\Document\Domain\Repository\DocumentSeriesRepository;
use Erpify\Documents\Document\Domain\Repository\UploadIntentRepository;
use Erpify\Documents\Document\Domain\Scanning\DocumentScanLeases;
use Erpify\Documents\Document\Domain\Upload\UploadIntentState;
use Erpify\Documents\Document\Domain\Versioning\DocumentVersionSlots;
use Erpify\Documents\Document\Infrastructure\Persistence\Doctrine\DbalDocumentErasures;
use Erpify\Documents\Document\Infrastructure\Persistence\Doctrine\DoctrineDocumentRepository;
use Erpify\Documents\Document\Infrastructure\Persistence\Doctrine\DoctrineUploadIntentRepository;
use Erpify\Shared\Crypto\Application\EnvelopeEncryptor;
use Erpify\Shared\Crypto\Application\Keystore;
use Erpify\Shared\Crypto\Domain\EncryptionScopeId;
use Erpify\Shared\Crypto\Domain\Exception\DekDestroyed;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Shared\Persistence\Domain\Exception\TransientTransactionFailure;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Double\Erasure\FakeErasureLedger;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Throwable;

/**
 * The operator's erasure against Postgres and the real keystore: from every non-terminal state, a repeat that writes
 * nothing, the ledger's three failure endings, a fault inside the transaction that rolls the whole of it back, and the
 * lock order read from the database rather than inferred.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 * @SuppressWarnings("PHPMD.ExcessiveClassLength") one test per ending and per crash point
 * @SuppressWarnings("PHPMD.TooManyMethods") the same
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 */
#[CoversClass(EraseDocument::class)]
#[CoversClass(ApplyErasure::class)]
#[CoversClass(DbalDocumentErasures::class)]
#[CoversClass(ErasureLedgerUnavailable::class)]
#[CoversClass(ErasureOutcomeUnknown::class)]
#[CoversClass(LedgerWriteFailed::class)]
#[CoversClass(LedgerWriteTimedOut::class)]
#[CoversClass(DoctrineDocumentRepository::class)]
#[CoversClass(DoctrineUploadIntentRepository::class)]
#[CoversClass(Document::class)]
final class EraseDocumentFunctionalTest extends KernelTestCase
{
    use InterleavesWithASecondConnection;
    use PlantsNamedDocuments;
    use PlantsUploadIntents;

    private TestHandler $log;

    private Logger $logger;

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->log = new TestHandler();
        $this->logger = new Logger('test', [$this->log]);
        FakeErasureLedger::reset();
        $this->emptyDocumentTables();
    }

    protected function tearDown(): void
    {
        $this->releaseTheHolder();
        FakeErasureLedger::reset();
        $this->emptyDocumentTables();
        parent::tearDown();
    }

    #[Test]
    #[DataProvider('provideAnOperatorErasesADocumentFromEveryNonTerminalStateCases')]
    public function anOperatorErasesADocumentFromEveryNonTerminalState(DocumentState $state): void
    {
        $document = $this->plantNamedDocument('plan.pdf', $state);
        $operator = Uuid::generate();

        $this->service(EraseDocument::class)->erase($document['id'], ErasureReason::DataSubjectRequest, $operator);

        $row = $this->rowOf($document['id']);
        $this->assertSame('erased', $row['state']);
        $this->assertNull($row['filename_ciphertext'], 'the tombstone holds no name');
        $this->assertSame(\hash('sha256', $document['id']), $row['digest']);
        $this->assertSame(10, $row['size_bytes']);
        $this->assertSame('erasure', $this->tombstoneReason($document['scope']));
        $this->assertTrue(FakeErasureLedger::has($document['scope']));
        $this->assertSame([['erased', $operator, 'data_subject_request']], $this->custodyOf($document['id']));
    }

    /**
     * @return iterable<string, array{DocumentState}>
     */
    public static function provideAnOperatorErasesADocumentFromEveryNonTerminalStateCases(): iterable
    {
        foreach (
            [
                DocumentState::Received,
                DocumentState::Scanning,
                DocumentState::Ready,
                DocumentState::Quarantined,
                DocumentState::ScanFailed,
            ] as $state
        ) {
            yield $state->value => [$state];
        }
    }

    #[Test]
    public function aRepeatAnswersAsTheFirstCallDidAndWritesNothing(): void
    {
        $document = $this->plantNamedDocument('plan.pdf');
        $erase = $this->service(EraseDocument::class);

        $erase->erase($document['id'], ErasureReason::UploadedInError, Uuid::generate());

        $tombstone = $this->tombstoneStamp($document['scope']);
        $attempts = \count(FakeErasureLedger::attempts());

        $erase->erase($document['id'], ErasureReason::LegalObligation, Uuid::generate());

        $this->assertCount(1, $this->custodyOf($document['id']), 'no second custody row');
        $this->assertCount($attempts, FakeErasureLedger::attempts(), 'no second ledger write');
        $this->assertSame($tombstone, $this->tombstoneStamp($document['scope']), 'no second tombstone');
    }

    #[Test]
    public function aDocumentWhoseKeyWasNeverMintedIsErasedAndItsScopeClosed(): void
    {
        $document = $this->plantNamedDocument(null, DocumentState::Received);
        $this->assertNull($this->tombstoneReason($document['scope']), 'no key row exists yet');

        $this->service(EraseDocument::class)->erase($document['id'], ErasureReason::RetentionExpired, Uuid::generate());

        $this->assertSame('erased', $this->rowOf($document['id'])['state']);
        $this->assertSame('erasure', $this->tombstoneReason($document['scope']));
    }

    #[Test]
    public function anUnknownDocumentIsRefusedBeforeAnythingIsWritten(): void
    {
        $erase = $this->service(EraseDocument::class);

        try {
            $erase->erase(Uuid::generate(), ErasureReason::DataSubjectRequest, Uuid::generate());
            $this->fail('An unknown document has no erasure.');
        } catch (DocumentNotFound) {
            $this->assertSame([], FakeErasureLedger::attempts());
        }
    }

    #[Test]
    public function aLedgerThatCannotMakeTheEntryDurableLeavesTheDocumentUntouched(): void
    {
        $document = $this->plantNamedDocument('plan.pdf');
        FakeErasureLedger::failWrites();

        try {
            $this->erase($document['id']);
            $this->fail('The erasure needs its ledger entry first.');
        } catch (ErasureLedgerUnavailable) {
            $this->assertUntouched($document);
            $this->assertFalse(FakeErasureLedger::has($document['scope']));
        }
    }

    #[Test]
    public function aWriteThatTimesOutIsOutcomeUnknownAndTheDocumentIsUntouchedWhetherOrNotItLanded(): void
    {
        $document = $this->plantNamedDocument('plan.pdf');
        $erase = $this->service(EraseDocument::class);

        FakeErasureLedger::timeOutWithoutLanding();
        $this->expectOutcomeUnknown($erase, $document['id']);
        $this->assertUntouched($document);
        $this->assertFalse(FakeErasureLedger::has($document['scope']));

        FakeErasureLedger::timeOutAfterLanding();
        $this->expectOutcomeUnknown($erase, $document['id']);
        $this->assertUntouched($document);
        $this->assertTrue(FakeErasureLedger::has($document['scope']));
        $this->assertTrue(FakeErasureLedger::has($document['scope']), 'an entry that landed is completed forward');
    }

    #[Test]
    public function aFaultAfterTheCompareAndSetAndBeforeTheDestructionRollsTheWholeTransactionBack(): void
    {
        $document = $this->plantNamedDocument('plan.pdf');
        $faulty = $this->eraseWith(keystore: new DestroyFaultingKeystore($this->service(Keystore::class)));

        $this->expectOutcomeUnknown($faulty, $document['id']);
        $this->assertUntouched($document);
        $this->assertTrue(FakeErasureLedger::has($document['scope']), 'durable, so the sweep will finish it');
        $this->assertTrue($this->log->hasRecordThatContains('failed after the ledger entry', Level::Error));

        $this->erase($document['id']);

        $this->assertSame('erased', $this->rowOf($document['id'])['state']);
        $this->assertSame('erasure', $this->tombstoneReason($document['scope']));
    }

    #[Test]
    #[DataProvider('provideATransactionFailureLogsItsCauseSlugOnlyCases')]
    public function aTransactionFailureLogsItsCauseSlugOnly(
        Throwable $failure,
        string $slug,
    ): void {
        $document = $this->plantNamedDocument('plan.pdf');
        $faulty = $this->eraseWith(keystore: new DestroyFaultingKeystore($this->service(Keystore::class), $failure));

        $this->expectOutcomeUnknown($faulty, $document['id']);

        $record = \array_first($this->log->getRecords());
        $this->assertInstanceOf(\Monolog\LogRecord::class, $record);
        $this->assertSame($slug, $record->context['cause'] ?? null);
        $this->assertStringNotContainsString('SQLSTATE', (string) \json_encode($record->context));
    }

    /**
     * @return iterable<string, array{Throwable, string}>
     */
    public static function provideATransactionFailureLogsItsCauseSlugOnlyCases(): iterable
    {
        yield 'a statement timeout' => [new RuntimeException('SQLSTATE[57014]: canceled'), 'statement-timeout'];
        yield 'a lock timeout wrapped by the driver' => [
            new RuntimeException('driver', 0, new RuntimeException('SQLSTATE[55P03]: lock not available')),
            'lock-timeout',
        ];
        yield 'a deadlock the transaction manager translated' => [
            new TransientTransactionFailure(new RuntimeException('deadlock detected')),
            'transient-transaction-failure',
        ];
        yield 'anything else' => [new RuntimeException('keystore is down'), 'erasure-transaction-failed'];
    }

    #[Test]
    public function aFaultWhileAppendingTheCustodyRowRollsBackTheCompareAndSetAndTheTombstoneTogether(): void
    {
        $document = $this->plantNamedDocument('plan.pdf');
        $failing = new ProbingDocumentCustodyRepository(
            $this->service(DocumentCustodyRepository::class),
            beforeAppend: static function (): never {
                throw new RuntimeException('An injected fault while appending the custody row.');
            },
        );
        $faulty = $this->eraseWith(custody: new AppendCustody(
            $this->service(DocumentRepository::class),
            $failing,
            $this->service(TransactionManager::class),
        ));

        $this->expectOutcomeUnknown($faulty, $document['id']);

        $this->assertUntouched($document);
        $this->assertSame('ready', $this->rowOf($document['id'])['state'], 'the compare-and-set rolled back');
        $this->assertNull($this->tombstoneReason($document['scope']), 'and so did the tombstone');
        $this->assertTrue($this->log->hasRecordThatContains('failed after the ledger entry', Level::Error));
    }

    #[Test]
    public function aSealRacingTheErasureOfAnUnmintedScopeLeavesATombstoneNeverALiveKey(): void
    {
        $document = $this->plantNamedDocument(null, DocumentState::Received);
        $scope = EncryptionScopeId::forDocument($document['scope']);
        $this->assertNull($this->tombstoneReason($document['scope']), 'the key was never minted');

        // The seal commits from another connection AFTER the erasure's `lockState()` saw the scope absent (nothing to
        // lock) and BEFORE its `destroy()`: the one window the tombstone's atomic upsert has to close.
        $racing = function (string $step) use ($scope): void {
            if ('after-lock' !== $step) {
                return;
            }

            $this->holder()->executeStatement(
                'INSERT INTO dek_keystore (encryption_scope_id, wrapped_dek, kek_version, created_at) '
                . 'VALUES (:scope, :dek, 1, NOW())',
                [
                    'scope' => $scope->toString(),
                    'dek' => \sodium_bin2base64(\random_bytes(32), SODIUM_BASE64_VARIANT_ORIGINAL),
                ],
            );
        };

        $this->eraseWith(keystore: new LockProbingKeystore($this->service(Keystore::class), $racing))
            ->erase($document['id'], ErasureReason::DataSubjectRequest, Uuid::generate())
        ;

        $this->assertSame('erasure', $this->tombstoneReason($document['scope']), 'the raced key is destroyed');
        $this->assertNull($this->service(Keystore::class)->wrappedDekFor($scope));

        // And a seal that arrives after the tombstone refuses instead of minting a fresh key.
        $this->expectException(DekDestroyed::class);
        $this->service(EnvelopeEncryptor::class)->encrypt($scope, 'x', 'probe');
    }

    #[Test]
    public function aScanWorkersLaterVerdictMatchesNothingOnceTheRowIsErased(): void
    {
        $document = $this->plantNamedDocument('plan.pdf', DocumentState::Scanning);
        $this->connection()->executeStatement(
            "UPDATE document SET lease_epoch = 1, claimed_at = NOW(), lease_expires_at = NOW() + INTERVAL '2 minutes' "
            . 'WHERE id = CAST(:id AS UUID)',
            ['id' => $document['id']],
        );

        $this->erase($document['id']);

        $leases = $this->service(DocumentScanLeases::class);
        $this->assertFalse($leases->settle($document['id'], 1, DocumentState::Ready));
        $this->assertFalse($leases->heartbeat($document['id'], 1));
        $this->assertSame('erased', $this->rowOf($document['id'])['state']);
    }

    #[Test]
    public function theLocksAreTakenInTheOrderIntentDocumentKeystoreAndAreHeldWhenTheStatementRuns(): void
    {
        $document = $this->plantNamedDocument('plan.pdf');
        $this->plantCommittedIntent($document);
        $observed = [];
        $probe = function (string $step) use (&$observed, $document): void {
            $observed[$step] = $this->heldRows($document);
        };

        $erase = $this->eraseWith(
            keystore: new LockProbingKeystore($this->service(Keystore::class), $probe),
            erasures: new LockProbingErasures($this->service(DocumentErasures::class), $probe),
        );
        $erase->erase($document['id'], ErasureReason::DataSubjectRequest, Uuid::generate());

        $none = ['intent' => false, 'document' => false, 'keystore' => false];
        $beforeTheKey = ['intent' => true, 'document' => true, 'keystore' => false];
        $all = ['intent' => true, 'document' => true, 'keystore' => true];

        // Read in the order the transaction took them: nothing held when it starts, the intent and the document row
        // before the keystore row is taken, all three when the compare-and-set and the destruction run.
        $this->assertSame([
            'bound' => $none,
            'before-lock' => $beforeTheKey,
            'after-lock' => $all,
            'before-cas' => $all,
            'before-destroy' => $all,
        ], $observed);
    }

    #[Test]
    public function anErasureBlockedBehindTheCommitsIntentLockTimesOutAsOutcomeUnknownAndChangesNothing(): void
    {
        $document = $this->plantNamedDocument('plan.pdf');
        $intent = $this->plantCommittedIntent($document);

        $this->holder()->beginTransaction();
        $this->holder()->fetchOne(
            'SELECT id FROM upload_intent WHERE id = CAST(:id AS UUID) FOR UPDATE',
            ['id' => $intent->id],
        );

        // Sharing the intent lock first is what serialises the erasure with the commit and the reaper; the 5 s
        // `lock_timeout` of its own transaction is what bounds the wait, and the entry is already durable.
        $this->expectOutcomeUnknown($this->eraseWith(), $document['id']);

        $this->holder()->rollBack();
        $this->assertSame('ready', $this->rowOf($document['id'])['state']);
        $this->assertTrue(FakeErasureLedger::has($document['scope']));
        $this->assertSame('lock-timeout', \array_first($this->log->getRecords())->context['cause'] ?? null);
    }

    /**
     * @param array{id: string, scope: string} $document
     *
     * @return array{intent: bool, document: bool, keystore: bool}
     */
    private function heldRows(array $document): array
    {
        return [
            'intent' => $this->isHeld(
                'SELECT 1 FROM upload_intent WHERE scope_id = CAST(:v AS UUID) FOR UPDATE NOWAIT',
                $document['scope'],
            ),
            'document' => $this->isHeld(
                'SELECT 1 FROM document WHERE id = CAST(:v AS UUID) FOR UPDATE NOWAIT',
                $document['id'],
            ),
            'keystore' => $this->isHeld(
                'SELECT 1 FROM dek_keystore WHERE encryption_scope_id = :v FOR UPDATE NOWAIT',
                EncryptionScopeId::forDocument($document['scope'])->toString(),
            ),
        ];
    }

    /** Asks from a second connection whether the row is locked: `NOWAIT` refuses at once instead of waiting. */
    private function isHeld(string $sql, string $value): bool
    {
        $second = $this->holder();
        $second->beginTransaction();

        try {
            $second->fetchOne($sql, ['v' => $value]);

            return false;
        } catch (Throwable $throwable) {
            $message = $throwable->getMessage();

            if (!\str_contains($message, '55P03') && !\str_contains($message, 'could not obtain lock')) {
                throw $throwable;
            }

            return true;
        } finally {
            $second->rollBack();
        }
    }

    private function erase(string $documentId): void
    {
        $this->service(EraseDocument::class)->erase($documentId, ErasureReason::DataSubjectRequest, Uuid::generate());
    }

    /**
     * @param array{id: string, scope: string} $document
     */
    private function plantCommittedIntent(array $document): PlantedIntent
    {
        return $this->plantIntent(
            Uuid::generate(),
            UploadIntentState::Committed,
            scopeId: $document['scope'],
            documentId: $document['id'],
        );
    }

    private function expectOutcomeUnknown(EraseDocument $erase, string $documentId): void
    {
        try {
            $erase->erase($documentId, ErasureReason::DataSubjectRequest, Uuid::generate());
            $this->fail('A timed-out write is outcome-unknown.');
        } catch (ErasureOutcomeUnknown) {
            $this->addToAssertionCount(1);
        }
    }

    private function eraseWith(
        ?Keystore $keystore = null,
        ?DocumentErasures $erasures = null,
        ?AppendCustody $custody = null,
    ): EraseDocument {
        $apply = new ApplyErasure(
            $this->service(UploadIntentRepository::class),
            $this->service(DocumentRepository::class),
            $erasures ?? $this->service(DocumentErasures::class),
            $custody ?? $this->service(AppendCustody::class),
            $keystore ?? $this->service(Keystore::class),
            $this->service(TransactionManager::class),
        );

        return new EraseDocument(
            $this->service(DocumentRepository::class),
            $this->service(ErasureLedger::class),
            $apply,
            $this->logger,
            $this->service(DocumentSeriesRepository::class),
            $this->service(DocumentVersionSlots::class),
        );
    }

    /**
     * @param array{id: string, scope: string} $document
     */
    private function assertUntouched(array $document): void
    {
        $row = $this->rowOf($document['id']);

        $this->assertNotSame('erased', $row['state']);
        $this->assertNotNull($row['filename_ciphertext']);
        $this->assertSame([], $this->custodyOf($document['id']));
        $this->assertSame('live', $this->tombstoneReason($document['scope']) ?? 'live');
    }

    /**
     * @return array{state: string, filename_ciphertext: ?string, digest: string, size_bytes: int}
     */
    private function rowOf(string $id): array
    {
        $row = $this->connection()->fetchAssociative(
            'SELECT state, filename_ciphertext, digest, size_bytes FROM document WHERE id = CAST(:id AS UUID)',
            ['id' => $id],
        );
        $this->assertIsArray($row);
        $ciphertext = $row['filename_ciphertext'] ?? null;

        return [
            'state' => $this->stringOf($row['state'] ?? null),
            'filename_ciphertext' => null === $ciphertext ? null : $this->stringOf($ciphertext),
            'digest' => $this->stringOf($row['digest'] ?? null),
            'size_bytes' => $this->intOf($row['size_bytes'] ?? null),
        ];
    }

    /**
     * @return list<array{string, ?string, ?string}> event, actor and reason of each custody row, in order
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

    /** The destroy reason of the scope's tombstone, or null when it has none (live, or no row at all). */
    private function tombstoneReason(string $scope): ?string
    {
        $reason = $this->connection()->fetchOne(
            'SELECT destroy_reason FROM dek_keystore WHERE encryption_scope_id = :scope AND destroyed_at IS NOT NULL',
            ['scope' => EncryptionScopeId::forDocument($scope)->toString()],
        );

        return \is_string($reason) ? $reason : null;
    }

    private function tombstoneStamp(string $scope): string
    {
        $stamp = $this->connection()->fetchOne(
            'SELECT destroyed_at FROM dek_keystore WHERE encryption_scope_id = :scope',
            ['scope' => EncryptionScopeId::forDocument($scope)->toString()],
        );
        $this->assertIsString($stamp);

        return $stamp;
    }
}
