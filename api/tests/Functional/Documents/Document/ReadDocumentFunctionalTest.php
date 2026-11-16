<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Documents\Document;

use Doctrine\DBAL\DriverManager;
use Erpify\Documents\Document\Application\Read\DocumentTransfer;
use Erpify\Documents\Document\Application\Read\ReadDocument;
use Erpify\Documents\Document\Application\Read\RunningDigest;
use Erpify\Documents\Document\Application\Upload\UploadEvidence;
use Erpify\Documents\Document\Domain\DocumentState;
use Erpify\Documents\Document\Domain\Entity\Document;
use Erpify\Documents\Document\Domain\Exception\DocumentGone;
use Erpify\Documents\Document\Domain\Exception\DocumentIntegrityFailure;
use Erpify\Documents\Document\Domain\Exception\DocumentNotFound;
use Erpify\Documents\Document\Domain\Exception\DocumentNotReadable;
use Erpify\Documents\Document\Domain\Repository\DocumentRepository;
use Erpify\Documents\Document\Domain\Storage\DocumentStorage;
use Erpify\Documents\Document\Domain\Storage\StorageArea;
use Erpify\Shared\Audit\Application\AuditLogger;
use Erpify\Shared\Crypto\Application\Keystore;
use Erpify\Shared\Crypto\Application\StreamEnvelopeEncryptor;
use Erpify\Shared\Crypto\Domain\DestroyReason;
use Erpify\Shared\Crypto\Domain\EncryptionScopeId;
use Erpify\Shared\ErrorContract\Domain\Exception\ClientError;
use Erpify\Shared\ErrorContract\Domain\Exception\Gone;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Shared\Uuid\Domain\Uuid;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use ReflectionClass;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The read boundary against real Postgres, the real document volume and the real sealer: one test per row of the
 * I/O matrix of doc-5 that the use case decides. `ReadDocument` is a registered reader in
 * `CryptoReadBoundaryGateTest`, and what it does at each of the two crypto causes is what the error contract's line
 * for it states: `dek-destroyed` answers `gone` (an alarm unless the re-read row is `Erased`) and `decryption-failed`
 * answers the marker-less `document-integrity-failure`.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 * @SuppressWarnings("PHPMD.TooManyMethods") one test per row of the matrix
 * @SuppressWarnings("PHPMD.ExcessiveClassLength") the same
 */
#[CoversClass(ReadDocument::class)]
#[CoversClass(DocumentTransfer::class)]
#[CoversClass(RunningDigest::class)]
#[CoversClass(DocumentGone::class)]
#[CoversClass(DocumentIntegrityFailure::class)]
final class ReadDocumentFunctionalTest extends KernelTestCase
{
    use PlantsScannableDocuments;

    private TestHandler $log;

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->emptyDocumentTables();
        $this->deleteReadRows();
        $this->log = new TestHandler();
    }

    protected function tearDown(): void
    {
        $this->discardScannableObjects();
        $this->deleteReadRows();
        $this->emptyDocumentTables();
        parent::tearDown();
    }

    #[Test]
    public function aReadyOriginalIsServedByteForByteAndAuditedOnceBeforeTheTransfer(): void
    {
        $plaintext = \random_bytes(200_000);
        $id = $this->fileReadyDocument($plaintext);

        $transfer = $this->reader()->open($id);

        $this->assertSame(200_000, $transfer->sizeBytes);
        $this->assertSame(1, $this->readRows($id), 'the row is written before pass 2 starts');

        $received = '';
        $this->assertTrue($transfer->sendTo(static function (string $bytes) use (&$received): void {
            $received .= $bytes;
        }));

        $this->assertSame($plaintext, $received);
        $this->assertSame(1, $this->readRows($id), 'the transfer adds no row');
        $this->assertSame([], $this->log->getRecords());
    }

    #[Test]
    public function theAuditRowIsSecurityLevelNamesTheDocumentAndCarriesNoMetadata(): void
    {
        $id = $this->fileReadyDocument('some evidence');

        $this->reader()->open($id);

        $row = $this->connection()->fetchAssociative(
            'SELECT level, resource_type, resource_id, metadata FROM audit_log WHERE action = :action',
            ['action' => ReadDocument::AUDIT_ACTION],
        );
        $this->assertIsArray($row);
        $this->assertSame('security', $row['level'] ?? null);
        $this->assertSame('Document', $row['resource_type'] ?? null);
        $this->assertSame($id, $row['resource_id'] ?? null);
        $this->assertSame([], \json_decode($this->stringOf($row['metadata'] ?? null), true));
    }

    #[Test]
    public function theAuditRowIsDurableOnTheAutocommitConnectionTheUseCaseRunsOn(): void
    {
        $id = $this->fileReadyDocument('some evidence');
        $this->assertFalse($this->connection()->isTransactionActive());

        $this->reader()->open($id);

        // Read through a second connection: only a committed row is visible to it.
        $other = DriverManager::getConnection($this->connection()->getParams());

        try {
            $this->assertSame(
                1,
                $this->intOf($other->fetchOne(
                    'SELECT COUNT(*) FROM audit_log WHERE action = :action AND resource_id = CAST(:id AS UUID)',
                    ['action' => ReadDocument::AUDIT_ACTION, 'id' => $id],
                )),
            );
        } finally {
            $other->close();
        }
    }

    #[Test]
    public function aFailedAuditWriteRefusesTheReadBeforeAnyTransferExists(): void
    {
        $id = $this->fileReadyDocument('some evidence');
        $failing = new ThrowingAuditLogger(new RuntimeException('the audit store is down'));

        try {
            $this->reader(audit: $failing)->open($id);
            $this->fail('A read whose audit row cannot be written must not be served.');
        } catch (RuntimeException $runtimeException) {
            $this->assertSame('the audit store is down', $runtimeException->getMessage());
        }

        $this->assertSame(0, $this->readRows($id));
    }

    #[Test]
    public function anUnknownIdIsNotFoundAndWritesNoRow(): void
    {
        $this->expectException(DocumentNotFound::class);

        try {
            $this->reader()->open(Uuid::generate());
        } finally {
            $this->assertSame(0, $this->allReadRows());
        }
    }

    #[Test]
    public function everyStateButReadyIsRefusedAsNotReadableAndWritesNoRow(): void
    {
        foreach (
            [
                DocumentState::Received,
                DocumentState::Scanning,
                DocumentState::Quarantined,
                DocumentState::ScanFailed,
            ] as $state
        ) {
            $id = $this->plantDocument(Uuid::generate(), $state);

            try {
                $this->reader()->open($id);
                $this->fail('A ' . $state->value . ' document must not be served.');
            } catch (DocumentNotReadable) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(0, $this->allReadRows());
    }

    #[Test]
    public function anErasedRowAnswersGoneWithNoAlarmAndNoRow(): void
    {
        $id = $this->plantDocument(Uuid::generate(), DocumentState::Erased);

        try {
            $this->reader()->open($id);
            $this->fail('An erased document must not be served.');
        } catch (DocumentGone) {
            $this->assertSame([], $this->log->getRecords());
            $this->assertSame(0, $this->allReadRows());
        }
    }

    #[Test]
    #[TestDox('A destroyed key (dek-destroyed) under a live row answers gone with an alarm and no audit row')]
    public function aDestroyedKeyUnderALiveRowAnswersGoneWithAnAlarm(): void
    {
        $id = $this->fileReadyDocument(\random_bytes(50_000));
        $this->destroyTheKeyOf($id);

        try {
            $this->reader()->open($id);
            $this->fail('A document whose key is destroyed must not be served.');
        } catch (DocumentGone) {
            $this->assertSame(['documentId' => $id, 'reason' => 'key-destroyed'], $this->alarmContext());
            $this->assertSame(0, $this->allReadRows());
        }
    }

    #[Test]
    #[TestDox('An erasure that lands between the snapshot and the open answers gone with no alarm')]
    public function anErasureBetweenTheSnapshotAndTheOpenAnswersGoneWithNoAlarm(): void
    {
        $id = $this->fileReadyDocument(\random_bytes(50_000));
        $document = $this->service(DocumentRepository::class)->findById($id);
        $this->assertInstanceOf(Document::class, $document);
        $scope = $document->scopeId();

        // The second connection commits what a concurrent erasure commits: the row `Erased`, the key destroyed and the
        // object deleted, all after the snapshot above was read and before the open.
        $erasure = DriverManager::getConnection($this->connection()->getParams());
        $storage = $this->service(DocumentStorage::class);
        $racing = new DocumentStorageThatRunsBeforeOpen($storage, static function () use (
            $erasure,
            $id,
            $storage,
            $scope,
        ): void {
            $erasure->executeStatement(
                "UPDATE document SET state = 'erased' WHERE id = CAST(:id AS UUID)",
                ['id' => $id],
            );
            $storage->delete(StorageArea::Final, $scope);
        });

        try {
            $this->reader(storage: $racing)->open($id);
            $this->fail('A document erased mid-read must not be served.');
        } catch (DocumentGone) {
            $this->assertSame([], $this->log->getRecords(), 'an erasure is an expected outcome, never an alarm');
            $this->assertSame(0, $this->allReadRows());
        } finally {
            $erasure->close();
        }
    }

    #[Test]
    #[TestDox('An altered object (decryption-failed) answers the integrity failure with an alarm and no audit row')]
    public function anAlteredObjectAnswersTheIntegrityFailureBeforeAnyByte(): void
    {
        $id = $this->fileReadyDocument(\random_bytes(100_000));
        $this->flipAByteOfTheFinalObject($id);

        $this->assertIntegrityFailure($id, 'authentication-failed');
    }

    #[Test]
    public function aMissingObjectAnswersTheIntegrityFailure(): void
    {
        $id = $this->fileReadyDocument('some evidence');
        $document = $this->service(DocumentRepository::class)->findById($id);
        $this->assertInstanceOf(Document::class, $document);
        $this->service(DocumentStorage::class)->delete(StorageArea::Final, $document->scopeId());

        $this->assertIntegrityFailure($id, 'object-missing');
    }

    #[Test]
    public function aDigestThatNoLongerMatchesTheRowAnswersTheIntegrityFailure(): void
    {
        $id = $this->fileReadyDocument('some evidence');
        $this->connection()->executeStatement(
            'UPDATE document SET digest = :digest WHERE id = CAST(:id AS UUID)',
            ['digest' => \hash('sha256', 'something else'), 'id' => $id],
        );

        $this->assertIntegrityFailure($id, 'digest-mismatch');
    }

    #[Test]
    public function aSizeThatNoLongerMatchesTheRowAnswersTheIntegrityFailure(): void
    {
        $id = $this->fileReadyDocument('some evidence');
        $this->connection()->executeStatement(
            'UPDATE document SET size_bytes = size_bytes + 1 WHERE id = CAST(:id AS UUID)',
            ['id' => $id],
        );

        $this->assertIntegrityFailure($id, 'size-mismatch');
    }

    #[Test]
    public function theIntegrityFailureCarriesNoMarkerSoItReachesTheErrorTracker(): void
    {
        $integrity = new ReflectionClass(DocumentIntegrityFailure::class);
        $gone = new ReflectionClass(DocumentGone::class);

        $this->assertFalse($integrity->implementsInterface(ClientError::class));
        $this->assertTrue($gone->implementsInterface(Gone::class));
    }

    #[Test]
    #[TestDox('An object swapped for another authentic one between the passes aborts the transfer one byte short')]
    public function anObjectSwappedBetweenThePassesAbortsTheTransferWithAnAlarm(): void
    {
        $original = \random_bytes(150_000);
        $id = $this->fileReadyDocument($original);
        $transfer = $this->reader()->open($id);

        // Same scope, same key, same length, so it authenticates: only the digest can tell it from the original.
        $this->resealTheFinalObject($id, \random_bytes(150_000));

        $received = '';
        $completed = $transfer->sendTo(static function (string $bytes) use (&$received): void {
            $received .= $bytes;
        });

        $this->assertFalse($completed);
        $this->assertSame(149_999, \strlen($received), 'the last byte is withheld, so the body is short of its length');
        $this->assertSame(['documentId' => $id, 'reason' => 'changed-between-passes'], $this->alarmContext());
    }

    #[Test]
    public function anObjectAlteredBetweenThePassesAbortsTheTransferWithAnAlarm(): void
    {
        $id = $this->fileReadyDocument(\random_bytes(150_000));
        $transfer = $this->reader()->open($id);
        $this->flipAByteOfTheFinalObject($id, 20);

        $received = '';
        $completed = $transfer->sendTo(static function (string $bytes) use (&$received): void {
            $received .= $bytes;
        });

        $this->assertFalse($completed);
        $this->assertLessThan(150_000, \strlen($received));
        $this->assertSame('authentication-failed', $this->alarmContext()['reason'] ?? null);
    }

    #[Test]
    public function anObjectReplacedByALongerOneNeverEmitsPastTheDeclaredLength(): void
    {
        $id = $this->fileReadyDocument(\random_bytes(100_000));
        $transfer = $this->reader()->open($id);
        $this->resealTheFinalObject($id, \random_bytes(250_000));

        $received = '';
        $this->assertFalse($transfer->sendTo(static function (string $bytes) use (&$received): void {
            $received .= $bytes;
        }));

        $this->assertLessThan(100_000, \strlen($received));
        $this->assertSame('size-mismatch', $this->alarmContext()['reason'] ?? null);
    }

    #[Test]
    public function aKeyDestroyedAfterTheVerificationAbortsTheTransferWithAnAlarm(): void
    {
        $id = $this->fileReadyDocument(\random_bytes(100_000));
        $transfer = $this->reader()->open($id);
        $this->destroyTheKeyOf($id);

        $this->assertFalse($transfer->sendTo(static function (): void {
        }));
        $this->assertSame(['documentId' => $id, 'reason' => 'key-destroyed'], $this->alarmContext());
    }

    #[Test]
    public function anUpperCasedIdIsAuditedAndAlarmedUnderTheCanonicalId(): void
    {
        $served = $this->fileReadyDocument('some evidence');
        $this->reader()->open(\strtoupper($served));
        $this->assertSame(1, $this->readRows($served), "the audit resource id is the row's own");

        $altered = $this->fileReadyDocument(\random_bytes(50_000));
        $this->flipAByteOfTheFinalObject($altered);

        try {
            $this->reader()->open(\strtoupper($altered));
            $this->fail('An altered document must not be served.');
        } catch (DocumentIntegrityFailure) {
            $this->assertSame(['documentId' => $altered, 'reason' => 'authentication-failed'], $this->alarmContext());
        }
    }

    #[Test]
    public function anObjectSwappedForAMuchLargerOneIsRefusedAtTheFirstChunkPastTheRowsSize(): void
    {
        $id = $this->fileReadyDocument(\str_repeat('a', 10_000));
        $this->connection()->executeStatement(
            'UPDATE document SET size_bytes = 2500 WHERE id = CAST(:id AS UUID)',
            ['id' => $id],
        );
        $encryptor = new ScriptedUnsealEncryptor(\str_repeat('a', 10_000), 1_000);

        try {
            $this->reader(encryptor: $encryptor)->open($id);
            $this->fail('An object larger than its row must not be served.');
        } catch (DocumentIntegrityFailure) {
            $this->assertSame(3, $encryptor->chunksOffered, 'pass 1 stops at the chunk that passes 2500 bytes');
            $this->assertSame(['documentId' => $id, 'reason' => 'size-mismatch'], $this->alarmContext());
            $this->assertSame(0, $this->allReadRows());
        }
    }

    #[Test]
    public function aFailureNoOneListedEndsPassTwoAsAnAlarmedAbortAndEscapesNothing(): void
    {
        $plaintext = \str_repeat('a', 4_000);
        $id = $this->fileReadyDocument($plaintext);
        $encryptor = new ScriptedUnsealEncryptor($plaintext, 1_000, new RuntimeException('the keystore is down'), 2);
        $transfer = $this->reader(encryptor: $encryptor)->open($id);

        $this->assertFalse($transfer->sendTo(static function (): void {
        }));
        $this->assertSame(['documentId' => $id, 'reason' => 'transfer-failed'], $this->alarmContext());
    }

    #[Test]
    public function aFailedReReadStillTranslatesTheOutcomeAndRaisesTheAlarm(): void
    {
        $id = $this->fileReadyDocument(\random_bytes(50_000));
        $this->flipAByteOfTheFinalObject($id);
        $documents = new DocumentRepositoryThatFailsToReRead($this->service(DocumentRepository::class));

        try {
            $this->reader(documents: $documents)->open($id);
            $this->fail('An altered document must not be served.');
        } catch (DocumentIntegrityFailure) {
            $this->assertSame(['documentId' => $id, 'reason' => 'authentication-failed'], $this->alarmContext());
        }
    }

    #[Test]
    public function aFailedReReadDuringTheTransferStillAlarmsTheAbort(): void
    {
        $id = $this->fileReadyDocument(\random_bytes(50_000));
        $transfer = $this->reader(documents: new DocumentRepositoryThatFailsToReRead(
            $this->service(DocumentRepository::class),
        ))->open($id);
        $this->flipAByteOfTheFinalObject($id, 20);

        $this->assertFalse($transfer->sendTo(static function (): void {
        }));
        $this->assertSame(['documentId' => $id, 'reason' => 'authentication-failed'], $this->alarmContext());
    }

    #[Test]
    public function aKeyDestroyedByAnErasureAfterTheVerificationAbortsTheTransferWithNoAlarm(): void
    {
        $id = $this->fileReadyDocument(\random_bytes(100_000));
        $transfer = $this->reader()->open($id);
        $this->destroyTheKeyOf($id);
        $this->connection()->executeStatement(
            "UPDATE document SET state = 'erased' WHERE id = CAST(:id AS UUID)",
            ['id' => $id],
        );

        $this->assertFalse($transfer->sendTo(static function (): void {
        }));
        $this->assertSame([], $this->log->getRecords());
    }

    private function assertIntegrityFailure(string $id, string $reason): void
    {
        try {
            $this->reader()->open($id);
            $this->fail('A document that cannot be verified must not be served.');
        } catch (DocumentIntegrityFailure) {
            $this->assertSame(['documentId' => $id, 'reason' => $reason], $this->alarmContext());
            $this->assertSame(0, $this->allReadRows(), 'a refused read writes no audit row');
        }
    }

    private function reader(
        ?AuditLogger $audit = null,
        ?DocumentStorage $storage = null,
        ?StreamEnvelopeEncryptor $encryptor = null,
        ?DocumentRepository $documents = null,
    ): ReadDocument {
        return new ReadDocument(
            $documents ?? $this->service(DocumentRepository::class),
            $storage ?? $this->service(DocumentStorage::class),
            $encryptor ?? $this->service(StreamEnvelopeEncryptor::class),
            $audit ?? $this->service(AuditLogger::class),
            new Logger('observability', [$this->log]),
        );
    }

    private function fileReadyDocument(string $plaintext): string
    {
        $id = $this->fileScannableDocument($plaintext);
        $this->connection()->executeStatement(
            "UPDATE document SET state = 'ready' WHERE id = CAST(:id AS UUID)",
            ['id' => $id],
        );

        return $id;
    }

    private function destroyTheKeyOf(string $id): void
    {
        $document = $this->service(DocumentRepository::class)->findById($id);
        $this->assertInstanceOf(Document::class, $document);
        $this->service(TransactionManager::class)->transactional(
            fn () => $this->service(Keystore::class)->destroy(
                EncryptionScopeId::forDocument($document->scopeId()),
                DestroyReason::Erasure,
            ),
        );
    }

    /**
     * Replaces the final object with a fresh sealing of `$plaintext` under the document's own scope.
     */
    private function resealTheFinalObject(string $id, string $plaintext): void
    {
        $document = $this->service(DocumentRepository::class)->findById($id);
        $this->assertInstanceOf(Document::class, $document);
        $scope = $document->scopeId();
        $storage = $this->service(DocumentStorage::class);
        $storage->delete(StorageArea::Final, $scope);

        $source = \fopen('php://memory', 'w+');
        $this->assertIsResource($source);
        \fwrite($source, $plaintext);
        \rewind($source);
        $sealer = $this->service(StreamEnvelopeEncryptor::class);
        $storage->stageWith($scope, static function ($sink) use ($sealer, $scope, $source): void {
            $sealer->seal(
                EncryptionScopeId::forDocument($scope),
                $source,
                $sink,
                UploadEvidence::ORIGINAL_ASSOCIATED_DATA,
            );
        });
        \fclose($source);
        $storage->promote($scope);
    }

    /**
     * @return array<string, mixed>
     */
    private function alarmContext(): array
    {
        $records = $this->log->getRecords();
        $this->assertCount(1, $records, 'exactly one alarm line');
        $record = \array_first($records);
        $this->assertSame('ERROR', $record->level->getName());

        /** @var array<string, mixed> $context */
        $context = $record->context;

        return $context;
    }

    private function readRows(string $documentId): int
    {
        return $this->intOf($this->connection()->fetchOne(
            'SELECT COUNT(*) FROM audit_log WHERE action = :action AND resource_id = CAST(:id AS UUID)',
            ['action' => ReadDocument::AUDIT_ACTION, 'id' => $documentId],
        ));
    }

    private function allReadRows(): int
    {
        return $this->intOf($this->connection()->fetchOne(
            'SELECT COUNT(*) FROM audit_log WHERE action = :action',
            ['action' => ReadDocument::AUDIT_ACTION],
        ));
    }

    private function deleteReadRows(): void
    {
        $this->connection()->executeStatement(
            'DELETE FROM audit_log WHERE action = :action',
            ['action' => ReadDocument::AUDIT_ACTION],
        );
    }
}
