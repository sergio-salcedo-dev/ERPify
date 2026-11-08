<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Documents\Document;

use Erpify\Documents\Document\Application\ReceiveDocument;
use Erpify\Documents\Document\Application\Upload\PromoteDocument;
use Erpify\Documents\Document\Application\Upload\PruneCommittedIntents;
use Erpify\Documents\Document\Application\Upload\ReapUploadIntents;
use Erpify\Documents\Document\Application\Upload\RollForwardPromotions;
use Erpify\Documents\Document\Application\Upload\SweepStagedOrphans;
use Erpify\Documents\Document\Domain\Repository\DocumentRepository;
use Erpify\Documents\Document\Domain\Repository\UploadIntentRepository;
use Erpify\Documents\Document\Domain\Storage\DocumentStorage;
use Erpify\Documents\Document\Domain\Storage\StorageArea;
use Erpify\Documents\Document\Domain\Upload\UploadIntentState;
use Erpify\Documents\Document\Domain\UploadLimits;
use Erpify\Shared\Crypto\Application\Keystore;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Functional\ResolvesContainerServices;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid as RandomUuid;

/**
 * The scheduled upload sweeps over real Postgres and the real volume: which intents each one selects, and that one
 * item which cannot be processed is logged and passed over rather than ending the batch — a poisoned candidate
 * would otherwise head every tick and stop everything behind it.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 * @SuppressWarnings("PHPMD.TooManyPublicMethods") one test per sweep and failure shape
 */
#[CoversClass(ReapUploadIntents::class)]
#[CoversClass(PruneCommittedIntents::class)]
#[CoversClass(RollForwardPromotions::class)]
#[CoversClass(SweepStagedOrphans::class)]
final class UploadSweepsFunctionalTest extends KernelTestCase
{
    use PlantsUploadIntents;
    use ResolvesContainerServices;

    /** @var list<string> */
    private array $scopes = [];

    private TestHandler $log;

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->log = new TestHandler();
    }

    protected function tearDown(): void
    {
        foreach ($this->scopes as $scope) {
            $this->storage()->delete(StorageArea::Staging, $scope);
            $this->storage()->delete(StorageArea::Final, $scope);
        }

        parent::tearDown();
    }

    public function testTheReaperEndsEveryReapableShapeAndNothingElse(): void
    {
        $actor = Uuid::generate();
        $pastTtl = $this->plantIntent($actor, UploadIntentState::Declared, claimed: false);
        $this->moveClock($pastTtl, "ttl_expires_at = NOW() - INTERVAL '1 second'");
        $pastLease = $this->plantIntent($actor, UploadIntentState::Streaming, leaseOffset: -1);
        $pastDeadline = $this->plantIntent($actor, UploadIntentState::Streaming);
        $this->moveClock($pastDeadline, "upload_deadline_at = NOW() - INTERVAL '1 second'");
        $expired = $this->plantIntent($actor, UploadIntentState::Expired);

        $liveDeclared = $this->plantIntent($actor, UploadIntentState::Declared, claimed: false);
        $liveStreaming = $this->plantIntent($actor, UploadIntentState::Streaming);
        $committed = $this->committedIntent($actor);

        $this->service(ReapUploadIntents::class)->reap(10_000);

        foreach ([$pastTtl, $pastLease, $pastDeadline, $expired] as $reaped) {
            $this->assertFalse($this->rowOf($reaped), 'a reapable intent is ended');
        }

        $this->assertNull($this->keyStateOf($pastTtl->scopeId), 'never claimed: no tombstone');

        foreach ([$pastLease, $pastDeadline, $expired] as $claimed) {
            $this->assertSame('aborted-upload', $this->keyStateOf($claimed->scopeId), 'a claimed scope is tombstoned');
        }

        foreach ([$liveDeclared, $liveStreaming, $committed] as $kept) {
            $this->assertNotFalse($this->rowOf($kept), 'a live or committed intent is left alone');
            $this->assertNull($this->keyStateOf($kept->scopeId));
        }
    }

    public function testThePruneDeletesOnlyCommittedIntentsPastTheirRetention(): void
    {
        $actor = Uuid::generate();
        $old = $this->committedIntent($actor);
        $this->moveClock($old, \sprintf(
            "committed_at = NOW() - make_interval(secs => %d) - INTERVAL '1 hour'",
            UploadLimits::COMMITTED_RETENTION_SECONDS,
        ));
        $recent = $this->committedIntent($actor);
        $liveDeclared = $this->plantIntent($actor, UploadIntentState::Declared, claimed: false);
        $liveStreaming = $this->plantIntent($actor, UploadIntentState::Streaming);

        $this->service(PruneCommittedIntents::class)->prune(10_000);

        $this->assertFalse($this->rowOf($old));

        foreach ([$recent, $liveDeclared, $liveStreaming] as $kept) {
            $this->assertNotFalse($this->rowOf($kept));
        }
    }

    public function testOneIntentThatCannotBeReapedDoesNotStopTheBatch(): void
    {
        $poisoned = $this->plantIntent(Uuid::generate(), UploadIntentState::Expired);
        $healthy = $this->plantIntent(Uuid::generate(), UploadIntentState::Expired);
        $storage = (new ScriptedDocumentStorage($this->storage()))->failDeleteFor($poisoned->scopeId);

        (new ReapUploadIntents(
            $this->service(UploadIntentRepository::class),
            $this->service(DocumentRepository::class),
            $this->service(Keystore::class),
            $storage,
            $this->service(TransactionManager::class),
            $this->logger(),
        ))->reap(10_000);

        $this->assertFalse($this->rowOf($healthy), 'the intent behind the poisoned one is reaped');
        $this->assertSame('aborted-upload', $this->keyStateOf($healthy->scopeId));
        $this->assertNotFalse($this->rowOf($poisoned), 'the poisoned intent stays for the next tick');
        $this->assertTrue($this->log->hasErrorThatContains('could not be reaped'));
    }

    public function testOneDocumentThatCannotBeRolledForwardDoesNotStopTheBatch(): void
    {
        $poisoned = $this->receivedWithStagedObject();
        $healthy = $this->receivedWithStagedObject();
        $storage = (new ScriptedDocumentStorage($this->storage()))->failPromoteFor($poisoned[1]);
        $documents = $this->service(DocumentRepository::class);
        $wakeUp = new RecordingScanWakeUp();

        (new RollForwardPromotions(
            $documents,
            new PromoteDocument($storage, $documents, $wakeUp),
            $wakeUp,
            $this->service(TransactionManager::class),
            $this->logger(),
        ))->run(UploadLimits::ROLL_FORWARD_AGE_SECONDS, 10_000);

        $this->assertNotNull($this->promotedAtOf($healthy[0]), 'the document behind the poisoned one is promoted');
        $this->assertNull($this->promotedAtOf($poisoned[0]), 'the poisoned document waits for the next tick');
        $this->assertTrue($this->log->hasErrorThatContains('could not be rolled forward'));
    }

    /**
     * The limit counts deletions: owned objects at the head of the walk are passed over without spending it, and a
     * scope that fails is logged and passed over too, so the orphans behind both are still reached.
     */
    public function testTheStagingSweepReachesOrphansBehindOwnedAndFailingScopes(): void
    {
        $ownedA = $this->plantIntent(Uuid::generate(), UploadIntentState::Declared, claimed: false)->scopeId;
        $ownedB = $this->plantIntent(Uuid::generate(), UploadIntentState::Declared, claimed: false)->scopeId;
        [$orphanA, $poisoned, $orphanB] = [$this->scope(), $this->scope(), $this->scope()];
        $storage = (new ScriptedDocumentStorage($this->storage()))
            ->walkYields([$ownedA, $ownedB, $orphanA, $poisoned, $orphanB])
            ->failDeleteFor($poisoned)
        ;

        $deleted = (new SweepStagedOrphans(
            $storage,
            $this->service(DocumentRepository::class),
            $this->service(UploadIntentRepository::class),
            $this->logger(),
        ))->sweep(2);

        $this->assertSame(2, $deleted);
        $this->assertSame([$orphanA, $orphanB], $storage->deleted);
        $this->assertTrue($this->log->hasErrorThatContains('could not be swept'));
    }

    private function committedIntent(string $actor): PlantedIntent
    {
        $scope = $this->scope();
        $document = $this->service(ReceiveDocument::class)->receive($scope, $actor, \hash('sha256', $scope), 5);

        return $this->plantIntent(
            $actor,
            UploadIntentState::Committed,
            scopeId: $scope,
            documentId: $document->documentId(),
        );
    }

    /**
     * @return array{string, string} the document and its scope, filed and staged, old enough to roll forward
     */
    private function receivedWithStagedObject(): array
    {
        $scope = $this->scope();
        $this->storage()->stageWith($scope, static function ($sink): void {
            \fwrite($sink, 'sealed');
        });
        $document = $this->service(ReceiveDocument::class)->receive(
            $scope,
            Uuid::generate(),
            \hash('sha256', 'sealed'),
            6,
        );
        $this->intentConnection()->executeStatement(
            "UPDATE document SET next_scan_at = NOW() - INTERVAL '1 hour' WHERE id = :id",
            ['id' => $document->documentId()],
        );

        return [$document->documentId(), $scope];
    }

    private function moveClock(PlantedIntent $intent, string $assignment): void
    {
        $this->intentConnection()->executeStatement(
            'UPDATE upload_intent SET ' . $assignment . ' WHERE id = :id',
            ['id' => $intent->id],
        );
    }

    private function rowOf(PlantedIntent $intent): mixed
    {
        return $this->intentConnection()->fetchOne('SELECT 1 FROM upload_intent WHERE id = :id', ['id' => $intent->id]);
    }

    private function promotedAtOf(string $documentId): mixed
    {
        return $this->intentConnection()->fetchOne(
            'SELECT promoted_at FROM document WHERE id = :id',
            ['id' => $documentId],
        );
    }

    private function scope(): string
    {
        $scope = RandomUuid::v4()->toRfc4122();
        $this->scopes[] = $scope;

        return $scope;
    }

    private function logger(): Logger
    {
        return new Logger('observability', [$this->log]);
    }

    private function storage(): DocumentStorage
    {
        return $this->service(DocumentStorage::class);
    }
}
