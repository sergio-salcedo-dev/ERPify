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
use Erpify\Documents\Document\Infrastructure\Messenger\Maintenance\PruneCommittedIntentsHandler;
use Erpify\Documents\Document\Infrastructure\Messenger\Maintenance\PruneCommittedIntentsMessage;
use Erpify\Documents\Document\Infrastructure\Messenger\Maintenance\ReapUploadIntentsHandler;
use Erpify\Documents\Document\Infrastructure\Messenger\Maintenance\ReapUploadIntentsMessage;
use Erpify\Documents\Document\Infrastructure\Messenger\Maintenance\RollForwardPromotionsHandler;
use Erpify\Documents\Document\Infrastructure\Messenger\Maintenance\RollForwardPromotionsMessage;
use Erpify\Documents\Document\Infrastructure\Messenger\Maintenance\SweepStagedOrphansHandler;
use Erpify\Documents\Document\Infrastructure\Messenger\Maintenance\SweepStagedOrphansMessage;
use Erpify\Documents\Document\Infrastructure\Persistence\Doctrine\DbalDocumentPromotions;
use Erpify\Documents\Document\Infrastructure\Persistence\Doctrine\DoctrineDocumentRepository;
use Erpify\Documents\Document\Infrastructure\Persistence\Doctrine\DoctrineUploadIntentRepository;
use Erpify\Shared\Crypto\Application\Keystore;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Functional\ResolvesContainerServices;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Monolog\LogRecord;
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
#[CoversClass(DoctrineUploadIntentRepository::class)]
#[CoversClass(DoctrineDocumentRepository::class)]
#[CoversClass(PruneCommittedIntentsHandler::class)]
#[CoversClass(ReapUploadIntentsHandler::class)]
#[CoversClass(RollForwardPromotionsHandler::class)]
#[CoversClass(SweepStagedOrphansHandler::class)]
#[CoversClass(DbalDocumentPromotions::class)]
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

    public function testEachTickHandlerRunsItsSweepWithoutHelp(): void
    {
        $actor = Uuid::generate();
        $expired = $this->plantIntent($actor, UploadIntentState::Expired);
        $old = $this->committedIntent($actor);
        $this->moveClock($old, \sprintf(
            "committed_at = NOW() - make_interval(secs => %d) - INTERVAL '1 hour'",
            UploadLimits::COMMITTED_RETENTION_SECONDS,
        ));

        $this->service(ReapUploadIntentsHandler::class)(new ReapUploadIntentsMessage());
        $this->service(PruneCommittedIntentsHandler::class)(new PruneCommittedIntentsMessage());
        $this->service(RollForwardPromotionsHandler::class)(new RollForwardPromotionsMessage());
        $this->service(SweepStagedOrphansHandler::class)(new SweepStagedOrphansMessage());

        $this->assertFalse($this->rowOf($expired), 'the reaper tick ended the expired intent');
        $this->assertFalse($this->rowOf($old), 'the prune tick deleted the committed intent past its retention');
    }

    public function testThePromotionIsClaimedOnceAndOnlyOnAReceivedRow(): void
    {
        $promotions = $this->service(DbalDocumentPromotions::class);
        $document = $this->service(ReceiveDocument::class)->receive(
            RandomUuid::v4()->toRfc4122(),
            Uuid::generate(),
            \hash('sha256', 'promoted'),
            8,
        );

        $this->assertTrue($promotions->markPromoted($document->documentId()), 'the first claim wins');
        $this->assertFalse($promotions->markPromoted($document->documentId()), 'a second claim finds it promoted');
        $this->assertFalse($promotions->markPromoted(Uuid::generate()), 'an absent row is never claimed');
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
            new PromoteDocument($storage, $documents, $wakeUp, $this->service(TransactionManager::class)),
            $wakeUp,
            $this->service(TransactionManager::class),
            $this->logger(),
        ))->run(UploadLimits::ROLL_FORWARD_AGE_SECONDS, 10_000);

        $this->assertNotNull($this->promotedAtOf($healthy[0]), 'the document behind the poisoned one is promoted');
        $this->assertNull($this->promotedAtOf($poisoned[0]), 'the poisoned document waits for the next tick');
        $this->assertTrue($this->log->hasErrorThatContains('could not be rolled forward'));
    }

    /**
     * Failing intents at the head of the candidates are paged past within the tick: with room for one success, the
     * healthy intent behind two that keep failing is still reaped. The real reaper runs first, so no other row of
     * the shared database is still a candidate that could take that one success.
     */
    public function testTheReaperPagesPastFailingIntentsToSpendItsLimitOnSuccesses(): void
    {
        $this->service(ReapUploadIntents::class)->reap(10_000);
        $first = $this->plantIntent(Uuid::generate(), UploadIntentState::Expired);
        $second = $this->plantIntent(Uuid::generate(), UploadIntentState::Expired);
        $healthy = $this->plantIntent(Uuid::generate(), UploadIntentState::Expired);
        $storage = (new ScriptedDocumentStorage($this->storage()))
            ->failDeleteFor($first->scopeId)
            ->failDeleteFor($second->scopeId)
        ;

        $reaped = (new ReapUploadIntents(
            $this->service(UploadIntentRepository::class),
            $this->service(DocumentRepository::class),
            $this->service(Keystore::class),
            $storage,
            $this->service(TransactionManager::class),
            $this->logger(),
        ))->reap(1);

        $this->assertSame(1, $reaped);
        $this->assertFalse($this->rowOf($healthy), 'the intent behind two failing ones is reaped');
        $this->assertNotFalse($this->rowOf($first));
        $this->assertNotFalse($this->rowOf($second));
        $this->assertSame([$first->id, $second->id], $this->loggedIds('intentId'), 'each failing row is named');
    }

    /**
     * The same for the roll-forward, whose other candidates are cleared the same way.
     */
    public function testTheRollForwardPagesPastFailingDocumentsToSpendItsLimitOnSuccesses(): void
    {
        $this->service(RollForwardPromotions::class)->run(UploadLimits::ROLL_FORWARD_AGE_SECONDS, 10_000);
        $first = $this->receivedWithStagedObject();
        $second = $this->receivedWithStagedObject();
        $healthy = $this->receivedWithStagedObject();
        $storage = (new ScriptedDocumentStorage($this->storage()))
            ->failPromoteFor($first[1])
            ->failPromoteFor($second[1])
        ;
        $documents = $this->service(DocumentRepository::class);
        $wakeUp = new RecordingScanWakeUp();

        $promoted = (new RollForwardPromotions(
            $documents,
            new PromoteDocument($storage, $documents, $wakeUp, $this->service(TransactionManager::class)),
            $wakeUp,
            $this->service(TransactionManager::class),
            $this->logger(),
        ))->run(UploadLimits::ROLL_FORWARD_AGE_SECONDS, 1);

        $this->assertSame(1, $promoted);
        $this->assertNotNull($this->promotedAtOf($healthy[0]), 'the document behind two failing ones is promoted');
        $this->assertSame([$first[0], $second[0]], $this->loggedIds('documentId'), 'each failing row is named');
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

    public function testAStagingWalkThatFailsEndsTheSweepWithOneLine(): void
    {
        $deleted = $this->sweepWith((new ScriptedDocumentStorage($this->storage()))->failWalk(), 10);

        $this->assertSame(0, $deleted);
        $this->assertCount(1, $this->log->getRecords());
        $this->assertTrue($this->log->hasErrorThatContains('could not be walked'));
    }

    /**
     * A store or database that fails every object stops the run after a few, rather than logging once per object
     * of the whole staging area every tick.
     */
    public function testARunOfFailuresStopsTheSweep(): void
    {
        $scopes = \array_map($this->scope(...), \range(1, 12));
        $storage = (new ScriptedDocumentStorage($this->storage()))->walkYields($scopes)->failEveryDelete();

        $this->assertSame(0, $this->sweepWith($storage, 10));
        $this->assertCount(5, \array_filter(
            $this->log->getRecords(),
            static fn (LogRecord $record): bool => \str_contains($record->message, 'could not be swept'),
        ));
        $this->assertTrue($this->log->hasErrorThatContains('stopped after consecutive failures'));
    }

    private function sweepWith(ScriptedDocumentStorage $storage, int $limit): int
    {
        return (new SweepStagedOrphans(
            $storage,
            $this->service(DocumentRepository::class),
            $this->service(UploadIntentRepository::class),
            $this->logger(),
        ))->sweep($limit);
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

    /**
     * @return list<mixed>
     */
    private function loggedIds(string $key): array
    {
        return \array_map(
            static fn (LogRecord $record): mixed => $record->context[$key] ?? null,
            \array_values(\array_filter(
                $this->log->getRecords(),
                static fn (LogRecord $record): bool => \array_key_exists($key, $record->context),
            )),
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
