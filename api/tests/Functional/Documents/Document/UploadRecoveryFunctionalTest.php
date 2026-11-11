<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Documents\Document;

use Doctrine\DBAL\Connection;
use Erpify\Documents\Document\Application\ReceiveDocument;
use Erpify\Documents\Document\Application\Upload\DocumentScanWakeUp;
use Erpify\Documents\Document\Application\Upload\PromoteDocument;
use Erpify\Documents\Document\Application\Upload\ReapUploadIntents;
use Erpify\Documents\Document\Application\Upload\RollForwardPromotions;
use Erpify\Documents\Document\Application\Upload\SweepStagedOrphans;
use Erpify\Documents\Document\Domain\Entity\Document;
use Erpify\Documents\Document\Domain\Repository\DocumentRepository;
use Erpify\Documents\Document\Domain\Storage\DocumentStorage;
use Erpify\Documents\Document\Domain\Storage\StorageArea;
use Erpify\Documents\Document\Domain\Upload\UploadIntentState;
use Erpify\Documents\Document\Domain\UploadLimits;
use Erpify\Documents\Document\Infrastructure\Messenger\ScanDocumentHandler;
use Erpify\Documents\Document\Infrastructure\Messenger\ScanDocumentMessage;
use Erpify\Documents\Document\Infrastructure\Persistence\Doctrine\DoctrineDocumentRepository;
use Erpify\Documents\Document\Infrastructure\Persistence\Doctrine\DoctrineUploadIntentRepository;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Functional\ResolvesContainerServices;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid as RandomUuid;

/**
 * A crash at each point of an upload, against the real document volume and Postgres: every one is finished by a
 * sweep with no duplicate and no lost object, a final object already present is accepted and never overwritten,
 * and nothing a committed row owns is ever deleted.
 *
 * The points, in order: before the commit; after it and before the rename; after the rename and before the
 * directory sync (both names on one inode); before `promoted_at`; while waking the scan, which rolls the mark back.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 */
#[CoversClass(RollForwardPromotions::class)]
#[CoversClass(PromoteDocument::class)]
#[CoversClass(SweepStagedOrphans::class)]
#[CoversClass(ReapUploadIntents::class)]
#[CoversClass(ScanDocumentHandler::class)]
#[CoversClass(DoctrineUploadIntentRepository::class)]
#[CoversClass(DoctrineDocumentRepository::class)]
final class UploadRecoveryFunctionalTest extends KernelTestCase
{
    use PlantsUploadIntents;
    use ResolvesContainerServices;

    /** @var list<string> */
    private array $scopes = [];

    /** @var array<int, string> */
    private array $wakeUps = [];

    /** @var list<bool> */
    private array $wakeInTransaction = [];

    private ?ScriptedDocumentStorage $promoting = null;

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();
    }

    protected function tearDown(): void
    {
        foreach ($this->scopes as $scope) {
            $this->storage()->delete(StorageArea::Staging, $scope);
            $this->storage()->delete(StorageArea::Final, $scope);
        }

        parent::tearDown();
    }

    public function testACrashBeforeTheCommitIsEndedByTheReaperWhichDeletesTheStagedObject(): void
    {
        $intent = $this->plantIntent(Uuid::generate(), UploadIntentState::Streaming, leaseOffset: -1);
        $this->scopes[] = $intent->scopeId;
        $this->mintKey($intent->scopeId);
        $this->stage($intent->scopeId, 'partial');

        $this->assertTrue($this->service(ReapUploadIntents::class)->reapOne($intent->id));

        $this->assertFalse($this->storage()->has(StorageArea::Staging, $intent->scopeId));
        $this->assertSame('aborted-upload', $this->keyStateOf($intent->scopeId));
    }

    public function testACrashAfterTheCommitAndBeforeTheRenameIsRolledForward(): void
    {
        $document = $this->committedWithStagedObject('sealed');

        $this->rollForward();

        $this->assertPromotedOnce($document, 'sealed');
    }

    public function testACrashAfterTheLinkAndBeforeTheUnlinkAcceptsTheFinalObject(): void
    {
        $document = $this->committedWithStagedObject('sealed');
        \link(
            $this->pathOf(StorageArea::Staging, $document->scopeId()),
            $this->ensuredPathOf(StorageArea::Final, $document->scopeId()),
        );

        $this->rollForward();

        $this->assertPromotedOnce($document, 'sealed');
        $this->assertSame(
            [$document->scopeId()],
            $this->promoting?->synced,
            'the final name is made durable before the staged one goes',
        );
    }

    public function testAFinalObjectAlreadyPresentIsAcceptedAndNeverOverwritten(): void
    {
        $document = $this->committedWithStagedObject('staged copy');
        \file_put_contents($this->ensuredPathOf(StorageArea::Final, $document->scopeId()), 'final copy');

        $this->rollForward();

        $this->assertPromotedOnce($document, 'final copy');
    }

    public function testACrashAfterTheRenameAndBeforePromotedAtIsFinished(): void
    {
        $document = $this->committedWithStagedObject('sealed');
        $this->storage()->promote($document->scopeId());

        $this->rollForward();

        $this->assertPromotedOnce($document, 'sealed');
        $this->assertSame(
            [$document->scopeId()],
            $this->promoting?->synced,
            'a final object found already moved is synced before it is reported promoted',
        );
    }

    public function testACrashWhileWakingTheScanRollsTheMarkBackAndTheRollForwardFinishesIt(): void
    {
        $document = $this->committedWithStagedObject('sealed');
        $crashWhileWaking = new class implements DocumentScanWakeUp {
            #[Override]
            public function wake(string $documentId): void
            {
                throw new RuntimeException(\sprintf('the writer died while waking the scan of %s', $documentId));
            }
        };

        try {
            (new PromoteDocument(
                $this->storage(),
                $this->service(DocumentRepository::class),
                $crashWhileWaking,
                $this->service(TransactionManager::class),
            ))->promote($document->documentId(), $document->scopeId());
            $this->fail('The simulated crash did not happen.');
        } catch (RuntimeException) {
            // The object moved; the mark and the message share one transaction, which rolled back.
        }

        $this->assertNull(
            $this->intentConnection()->fetchOne(
                'SELECT promoted_at FROM document WHERE id = :id',
                ['id' => $document->documentId()],
            ),
            'the mark is undone with the message that could not be published',
        );

        $this->rollForward();

        $this->assertPromotedOnce($document, 'sealed');
    }

    public function testTheRollForwardWakesTheScanInsideItsTransaction(): void
    {
        $document = $this->committedWithStagedObject('sealed');

        $this->rollForward();

        $this->assertSame([$document->documentId()], $this->wakeUps);
        $this->assertSame([true], $this->wakeInTransaction, 'the message commits with the mark or not at all');
    }

    public function testAWakeUpThatFailsInTheRollForwardRollsTheMarkBackAndIsLoggedByTheDocumentId(): void
    {
        $document = $this->committedWithStagedObject('sealed');
        $failing = new class implements DocumentScanWakeUp {
            #[Override]
            public function wake(string $documentId): void
            {
                throw new RuntimeException('the outbox refused ' . $documentId);
            }
        };
        $log = new TestHandler();

        $promoted = $this->rollForward($failing, new Logger('observability', [$log]));

        $this->assertSame(0, $promoted, 'a row whose message could not be published is not counted as promoted');
        $this->assertNull(
            $this->intentConnection()->fetchOne(
                'SELECT promoted_at FROM document WHERE id = :id',
                ['id' => $document->documentId()],
            ),
            'the mark is undone with the message',
        );
        $this->assertTrue($log->hasErrorThatContains('could not be rolled forward'));
        $context = \array_first($log->getRecords())->context ?? [];
        $this->assertSame($document->documentId(), $context['documentId'] ?? null);
        $this->assertStringNotContainsString('refused', \json_encode($context) ?: '', 'the cause stays out');
    }

    public function testTheStagingSweepNeverDeletesAnObjectARowOwnsAndTheRollForwardStillPromotesIt(): void
    {
        $document = $this->committedWithStagedObject('sealed');
        \touch(
            $this->pathOf(StorageArea::Staging, $document->scopeId()),
            \time() - UploadLimits::STAGING_ORPHAN_AGE_SECONDS - 60,
        );

        $this->service(SweepStagedOrphans::class)->sweep(10_000);

        $this->assertTrue(
            $this->storage()->has(StorageArea::Staging, $document->scopeId()),
            'a committed row owns its staged object',
        );

        $this->rollForward();

        $this->assertPromotedOnce($document, 'sealed');
    }

    public function testTheStagingSweepDeletesAnOldObjectNobodyOwnsAndKeepsALiveIntentsOne(): void
    {
        $orphan = RandomUuid::v4()->toRfc4122();
        $this->scopes[] = $orphan;
        $this->stage($orphan, 'orphan');
        \touch($this->pathOf(StorageArea::Staging, $orphan), \time() - UploadLimits::STAGING_ORPHAN_AGE_SECONDS - 60);

        $live = $this->plantIntent(Uuid::generate(), UploadIntentState::Streaming);
        $this->scopes[] = $live->scopeId;
        $this->stage($live->scopeId, 'in flight');
        \touch(
            $this->pathOf(StorageArea::Staging, $live->scopeId),
            \time() - UploadLimits::STAGING_ORPHAN_AGE_SECONDS - 60,
        );

        $this->service(SweepStagedOrphans::class)->sweep(10_000);

        $this->assertFalse($this->storage()->has(StorageArea::Staging, $orphan));
        $this->assertTrue($this->storage()->has(StorageArea::Staging, $live->scopeId));
    }

    public function testTheScanHandlerLeavesTheRowUntouched(): void
    {
        $document = $this->committedWithStagedObject('sealed');
        $before = $this->intentConnection()->fetchAssociative(
            'SELECT * FROM document WHERE id = :id',
            ['id' => $document->documentId()],
        );

        (new ScanDocumentHandler())(new ScanDocumentMessage($document->documentId()));

        $this->assertSame(
            $before,
            $this->intentConnection()->fetchAssociative(
                'SELECT * FROM document WHERE id = :id',
                ['id' => $document->documentId()],
            ),
        );
    }

    private function committedWithStagedObject(string $bytes): Document
    {
        $scope = RandomUuid::v4()->toRfc4122();
        $this->scopes[] = $scope;
        $this->stage($scope, $bytes);

        $document = $this->service(ReceiveDocument::class)->receive(
            $scope,
            Uuid::generate(),
            \hash('sha256', $bytes),
            \strlen($bytes),
        );
        // Old enough for the roll-forward, as a row whose writer died minutes ago would be.
        $this->intentConnection()->executeStatement(
            "UPDATE document SET next_scan_at = NOW() - INTERVAL '1 hour' WHERE id = :id",
            ['id' => $document->documentId()],
        );

        return $document;
    }

    /**
     * @return int how many rows the run promoted
     */
    private function rollForward(?DocumentScanWakeUp $wakeUp = null, ?LoggerInterface $logger = null): int
    {
        $recording = new RecordingScanWakeUp($this->service(Connection::class));
        $wakeUp ??= $recording;
        $documents = $this->service(DocumentRepository::class);
        $this->promoting = new ScriptedDocumentStorage($this->storage());

        $promoted = (new RollForwardPromotions(
            $documents,
            new PromoteDocument($this->promoting, $documents, $wakeUp, $this->service(TransactionManager::class)),
            $wakeUp,
            $this->service(TransactionManager::class),
            $logger ?? new NullLogger(),
        ))->run(UploadLimits::ROLL_FORWARD_AGE_SECONDS, 10_000);

        $this->wakeUps = $recording->woken;
        $this->wakeInTransaction = $recording->insideTransaction;

        return $promoted;
    }

    private function assertPromotedOnce(Document $document, string $finalBytes): void
    {
        $this->assertFalse($this->storage()->has(StorageArea::Staging, $document->scopeId()));
        $final = $this->storage()->open($document->scopeId());
        $this->assertSame($finalBytes, \stream_get_contents($final));
        \fclose($final);
        $this->assertNotNull($this->intentConnection()->fetchOne(
            'SELECT promoted_at FROM document WHERE id = :id',
            ['id' => $document->documentId()],
        ));
        $this->assertCount(1, \array_keys($this->wakeUps, $document->documentId(), true), 'one wake-up');
    }

    private function stage(string $scope, string $bytes): void
    {
        $this->storage()->stageWith($scope, static function ($sink) use ($bytes): void {
            \fwrite($sink, $bytes);
        });
    }

    private function pathOf(StorageArea $area, string $scope): string
    {
        $root = self::getContainer()->getParameter('erpify.documents.storage_root');
        $this->assertIsString($root);

        return \sprintf('%s/%s/%s/%s/%s', $root, $area->value, \substr($scope, -4, 2), \substr($scope, -2), $scope);
    }

    private function ensuredPathOf(StorageArea $area, string $scope): string
    {
        $path = $this->pathOf($area, $scope);

        if (!\is_dir(\dirname($path))) {
            \mkdir(\dirname($path), 0o750, true);
        }

        return $path;
    }

    private function storage(): DocumentStorage
    {
        return $this->service(DocumentStorage::class);
    }
}
