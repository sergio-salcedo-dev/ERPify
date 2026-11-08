<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Documents\Document;

use Erpify\Documents\Document\Application\ReceiveDocument;
use Erpify\Documents\Document\Application\Upload\ClaimUpload;
use Erpify\Documents\Document\Application\Upload\Command\DeclareUploadCommand;
use Erpify\Documents\Document\Application\Upload\Command\UploadEvidenceCommand;
use Erpify\Documents\Document\Application\Upload\CommitUpload;
use Erpify\Documents\Document\Application\Upload\DeclareUpload;
use Erpify\Documents\Document\Application\Upload\DocumentScanWakeUp;
use Erpify\Documents\Document\Application\Upload\ExpireUpload;
use Erpify\Documents\Document\Application\Upload\PromoteDocument;
use Erpify\Documents\Document\Application\Upload\UploadEvidence;
use Erpify\Documents\Document\Application\Upload\UploadTimeBudget;
use Erpify\Documents\Document\Domain\DocumentState;
use Erpify\Documents\Document\Domain\Exception\UploadAborted;
use Erpify\Documents\Document\Domain\FilenameSubmission;
use Erpify\Documents\Document\Domain\Repository\DocumentRepository;
use Erpify\Documents\Document\Domain\Repository\UploadIntentRepository;
use Erpify\Documents\Document\Domain\Storage\DocumentStorage;
use Erpify\Documents\Document\Domain\Storage\StorageArea;
use Erpify\Documents\Document\Domain\Upload\LegalBasis;
use Erpify\Documents\Document\Domain\Upload\RetentionReason;
use Erpify\Documents\Document\Domain\Upload\UploadIntentState;
use Erpify\Shared\Crypto\Application\StreamEnvelopeEncryptor;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Functional\ResolvesContainerServices;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid as RandomUuid;
use Throwable;

/**
 * What an upload leaves when something other than the caller fails — the database under a heartbeat, the disk
 * under the seal, the promotion after the commit — and what an abort leaves when a commit it could not see
 * succeeded. Against real Postgres and the real document volume; each fault is injected at one seam, and the
 * rest is the container's own wiring.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversClass(UploadEvidence::class)]
#[CoversClass(ExpireUpload::class)]
final class UploadFailureFunctionalTest extends KernelTestCase
{
    use PlantsUploadIntents;
    use ResolvesContainerServices;

    private const int SIZE = 300_000;

    /** @var list<string> */
    private array $scopes = [];

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();
    }

    protected function tearDown(): void
    {
        foreach ($this->scopes as $scope) {
            $this->service(DocumentStorage::class)->delete(StorageArea::Staging, $scope);
            $this->service(DocumentStorage::class)->delete(StorageArea::Final, $scope);
        }

        parent::tearDown();
    }

    public function testAnAbortThatMeetsACommittedUploadLeavesItsStagedObject(): void
    {
        $scope = RandomUuid::v4()->toRfc4122();
        $this->scopes[] = $scope;
        $document = $this->service(ReceiveDocument::class)->receive(
            $scope,
            Uuid::generate(),
            \hash('sha256', 'filed'),
            5,
        );
        $intent = $this->plantIntent(
            Uuid::generate(),
            UploadIntentState::Committed,
            scopeId: $scope,
            documentId: $document->documentId(),
        );
        $this->service(DocumentStorage::class)->stageWith($scope, static function ($sink): void {
            \fwrite($sink, 'filed');
        });

        // The writer's commit reached the server and its answer was lost: the abort runs on a committed intent.
        $this->service(ExpireUpload::class)->expire($intent->id, $scope);

        $this->assertTrue(
            $this->service(DocumentStorage::class)->has(StorageArea::Staging, $scope),
            'the committed document owns its staged object',
        );
        $this->assertSame('committed', $this->stateOf($intent->id));
    }

    public function testADatabaseFaultInAHeartbeatIsTheServersFaultNotAnAbortedUpload(): void
    {
        [$actor, $upload, $scope] = $this->declared();
        $fault = new RuntimeException('the database connection was lost');
        $intents = new FaultingLockIntentRepository($this->service(UploadIntentRepository::class), $fault);

        $thrown = $this->uploadCatching($this->useCase(intents: $intents), $actor, $upload);

        $this->assertSame($fault, $thrown, 'the fault propagates as itself, never as an aborted upload');
        $this->assertSame('expired', $this->stateOf($upload));
        $this->assertFalse($this->service(DocumentStorage::class)->has(StorageArea::Staging, $scope));
        $this->assertNoDocumentFor($scope);
    }

    public function testASinkThatFailsMidSealIsTheServersFaultAndEndsTheUpload(): void
    {
        [$actor, $upload, $scope] = $this->declared();
        $storage = (new ScriptedDocumentStorage($this->service(DocumentStorage::class)))->failSinkAfter(100_000);

        $thrown = $this->uploadCatching($this->useCase(storage: $storage), $actor, $upload);

        $this->assertInstanceOf(Throwable::class, $thrown, 'a seal into a full disk was filed');
        $this->assertNotInstanceOf(UploadAborted::class, $thrown, "a full disk is not the caller's aborted upload");
        $this->assertSame('expired', $this->stateOf($upload));
        $this->assertFalse($this->service(DocumentStorage::class)->has(StorageArea::Staging, $scope));
        $this->assertNoDocumentFor($scope);
    }

    public function testAPromotionThatFailsAfterTheCommitStillFilesTheDocument(): void
    {
        [$actor, $upload, $scope] = $this->declared();
        $storage = (new ScriptedDocumentStorage($this->service(DocumentStorage::class)))->failPromoteFor($scope);
        $promote = new PromoteDocument(
            $storage,
            $this->service(DocumentRepository::class),
            $this->service(DocumentScanWakeUp::class),
        );

        $outcome = $this->useCase(promote: $promote)->upload($this->command($actor, $upload));

        $this->assertTrue($outcome->created, 'answered as filed (201)');
        $this->assertSame(DocumentState::Received, $outcome->document->state());
        $this->assertNull($this->intentConnection()->fetchOne(
            'SELECT promoted_at FROM document WHERE id = :id',
            ['id' => $outcome->document->documentId()],
        ), 'left for the roll-forward');
        $this->assertSame('committed', $this->stateOf($upload));
        $this->assertTrue(
            $this->service(DocumentStorage::class)->has(StorageArea::Staging, $scope),
            'the staged object is kept for the roll-forward to move',
        );
        $this->assertFalse($this->service(DocumentStorage::class)->has(StorageArea::Final, $scope));
    }

    /**
     * @return array{string, string, string} the actor, the upload and its scope
     */
    private function declared(): array
    {
        $actor = Uuid::generate();
        $outcome = $this->service(DeclareUpload::class)->declare(
            new DeclareUploadCommand(
                size: self::SIZE,
                retentionReason: RetentionReason::TechnicalFile,
                legalBasis: LegalBasis::Contract,
            ),
            $actor,
        );
        $upload = (string) $outcome->intentId();
        $this->forgetAfterTest($upload);
        $scope = $this->intentConnection()->fetchOne('SELECT scope_id FROM upload_intent WHERE id = :id', [
            'id' => $upload,
        ]);
        $this->assertIsString($scope);
        $this->scopes[] = $scope;

        return [$actor, $upload, $scope];
    }

    private function uploadCatching(UploadEvidence $useCase, string $actor, string $upload): ?Throwable
    {
        try {
            $useCase->upload($this->command($actor, $upload));
        } catch (Throwable $throwable) {
            return $throwable;
        }

        return null;
    }

    private function command(string $actor, string $upload): UploadEvidenceCommand
    {
        return new UploadEvidenceCommand(
            uploadId: $upload,
            actorId: $actor,
            contentLength: self::SIZE,
            filename: FilenameSubmission::fromQueryValues(['failure.bin']),
            body: ScriptedBody::open(self::SIZE),
        );
    }

    private function useCase(
        ?UploadIntentRepository $intents = null,
        ?DocumentStorage $storage = null,
        ?PromoteDocument $promote = null,
    ): UploadEvidence {
        return new UploadEvidence(
            $this->service(ClaimUpload::class),
            $this->service(CommitUpload::class),
            $this->service(ExpireUpload::class),
            $promote ?? $this->service(PromoteDocument::class),
            $this->service(StreamEnvelopeEncryptor::class),
            $storage ?? $this->service(DocumentStorage::class),
            $intents ?? $this->service(UploadIntentRepository::class),
            $this->service(TransactionManager::class),
            $this->service(UploadTimeBudget::class),
            heartbeatIntervalSeconds: 0,
        );
    }

    private function stateOf(string $upload): mixed
    {
        return $this->intentConnection()->fetchOne('SELECT state FROM upload_intent WHERE id = :id', ['id' => $upload]);
    }

    private function assertNoDocumentFor(string $scope): void
    {
        $this->assertFalse($this->intentConnection()->fetchOne(
            'SELECT 1 FROM document WHERE scope_id = :scope',
            ['scope' => $scope],
        ));
    }
}
