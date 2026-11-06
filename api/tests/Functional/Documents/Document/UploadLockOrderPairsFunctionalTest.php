<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Documents\Document;

use Erpify\Documents\Document\Application\PurgeUserDocumentReferences;
use Erpify\Documents\Document\Application\ReceiveDocument;
use Erpify\Documents\Document\Application\Upload\ClaimedUpload;
use Erpify\Documents\Document\Application\Upload\ClaimUpload;
use Erpify\Documents\Document\Application\Upload\Command\UploadEvidenceCommand;
use Erpify\Documents\Document\Application\Upload\CommitUpload;
use Erpify\Documents\Document\Application\Upload\ReapUploadIntents;
use Erpify\Documents\Document\Application\Upload\RollForwardPromotions;
use Erpify\Documents\Document\Domain\Entity\Document;
use Erpify\Documents\Document\Domain\ErasedPersonMarker;
use Erpify\Documents\Document\Domain\Exception\UploadAborted;
use Erpify\Documents\Document\Domain\Exception\UploadGone;
use Erpify\Documents\Document\Domain\FilenameSubmission;
use Erpify\Documents\Document\Domain\OriginalFilename;
use Erpify\Documents\Document\Domain\Storage\DocumentStorage;
use Erpify\Documents\Document\Domain\Storage\StorageArea;
use Erpify\Documents\Document\Domain\Upload\UploadIntentState;
use Erpify\Documents\Document\Domain\UploadLimits;
use Erpify\Shared\Crypto\Domain\EncryptionScopeId;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Functional\ResolvesContainerServices;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid as RandomUuid;

/**
 * Every pair of the five flows that touch an upload — the claim, the commit, the reaper, the person purge and the
 * roll-forward — interleaved for real (ADR `documents-custody-log.md` D6). The reaper-vs-commit pair in both
 * orders and purge-waits-on-commit live in {@see UploadLockOrderFunctionalTest}; every other pair is here.
 *
 * Each case lets a second connection hold what one side holds mid-transaction and shows the other side either
 * WAITS on it and, once the holder commits, rechecks under the lock and takes the one outcome left to it — or,
 * for the pairs whose lock sets are disjoint, completes without waiting at all. A pair that would deadlock shows
 * as a lock timeout on the side that should have completed, so "no deadlock" is asserted, not assumed.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 * @SuppressWarnings("PHPMD.TooManyPublicMethods") one test per pair and direction
 * @SuppressWarnings("PHPMD.ExcessiveClassLength") the same
 */
#[CoversClass(ClaimUpload::class)]
#[CoversClass(CommitUpload::class)]
#[CoversClass(ReapUploadIntents::class)]
#[CoversClass(PurgeUserDocumentReferences::class)]
#[CoversClass(RollForwardPromotions::class)]
final class UploadLockOrderPairsFunctionalTest extends KernelTestCase
{
    use InterleavesWithASecondConnection;
    use PlantsUploadIntents;
    use ResolvesContainerServices;

    /** @var list<string> */
    private array $scopes = [];

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();
    }

    protected function tearDown(): void
    {
        $this->releaseTheHolder();

        foreach ($this->scopes as $scope) {
            $this->service(DocumentStorage::class)->delete(StorageArea::Staging, $scope);
            $this->service(DocumentStorage::class)->delete(StorageArea::Final, $scope);
        }

        parent::tearDown();
    }

    public function testAClaimMeetingACommitInFlightWaitsAndThenAnswersTheDocument(): void
    {
        $actor = Uuid::generate();
        $intent = $this->plantIntent($actor, UploadIntentState::Streaming);

        $holder = $this->holder();
        $holder->beginTransaction();
        $this->lockIntentOn($intent->id);
        $documentId = $this->insertDocumentFor($holder, $intent->scopeId);
        $holder->executeStatement(
            "UPDATE upload_intent SET state = 'committed', document_id = :d, committed_at = NOW() WHERE id = :id",
            ['d' => $documentId, 'id' => $intent->id],
        );

        $this->assertTrue($this->waits(fn (): ClaimedUpload|Document => $this->claim($actor, $intent->id)));

        $holder->commit();

        $answer = $this->claim($actor, $intent->id);
        $this->assertInstanceOf(Document::class, $answer);
        $this->assertSame($documentId, $answer->documentId());
    }

    public function testACommitMeetingAClaimInFlightWaitsAndThenFilesTheDocument(): void
    {
        $actor = Uuid::generate();
        $intent = $this->plantIntent($actor, UploadIntentState::Declared, claimed: false);
        $this->mintKey($intent->scopeId);

        // The claim, mid-transaction: the intent row and the claim slots, the intent moved to Streaming.
        $holder = $this->holder();
        $holder->beginTransaction();
        $this->lockIntentOn($intent->id);
        $holder->executeStatement(
            "UPDATE upload_intent SET state = 'streaming', claimed_at = NOW(), "
            . "lease_expires_at = NOW() + INTERVAL '90 seconds', upload_deadline_at = NOW() + INTERVAL '10 minutes' "
            . 'WHERE id = :id',
            ['id' => $intent->id],
        );

        $claimed = new ClaimedUpload($intent->id, $intent->scopeId, 10, null, OriginalFilename::fromString('a.pdf'));
        $this->assertTrue($this->waits(
            fn () => $this->service(CommitUpload::class)->commit($claimed, \hash('sha256', 'x')),
        ));

        $holder->commit();

        $this->service(CommitUpload::class)->commit($claimed, \hash('sha256', 'x'));
        $this->assertSame('committed', $this->stateOf($intent->id));
    }

    public function testAClaimMeetingTheReaperInFlightWaitsAndThenFindsItGone(): void
    {
        $actor = Uuid::generate();
        $intent = $this->plantIntent($actor, UploadIntentState::Declared, claimed: false);

        $holder = $this->holder();
        $holder->beginTransaction();
        $this->lockIntentOn($intent->id);
        $holder->executeStatement('DELETE FROM upload_intent WHERE id = :id', ['id' => $intent->id]);

        $this->assertTrue($this->waits(fn (): ClaimedUpload|Document => $this->claim($actor, $intent->id)));

        $holder->commit();

        $this->expectException(UploadGone::class);
        $this->claim($actor, $intent->id);
    }

    public function testTheReaperMeetingAClaimInFlightWaitsAndThenStandsDown(): void
    {
        $intent = $this->plantIntent(Uuid::generate(), UploadIntentState::Declared, claimed: false);
        // Past its TTL as the claim began: the reaper's candidate query selects it.
        $this->intentConnection()->executeStatement(
            "UPDATE upload_intent SET ttl_expires_at = NOW() - INTERVAL '1 second' WHERE id = :id",
            ['id' => $intent->id],
        );

        $holder = $this->holder();
        $holder->beginTransaction();
        $this->lockIntentOn($intent->id);
        $holder->executeStatement(
            "UPDATE upload_intent SET state = 'streaming', claimed_at = NOW(), "
            . "lease_expires_at = NOW() + INTERVAL '90 seconds', upload_deadline_at = NOW() + INTERVAL '10 minutes', "
            . "ttl_expires_at = NOW() + INTERVAL '1 day' WHERE id = :id",
            ['id' => $intent->id],
        );

        $this->assertTrue($this->waits(fn () => $this->service(ReapUploadIntents::class)->reapOne($intent->id)));

        $holder->commit();

        $this->assertFalse(
            $this->service(ReapUploadIntents::class)->reapOne($intent->id),
            'a live stream is not reaped',
        );
        $this->assertSame('streaming', $this->stateOf($intent->id));
    }

    public function testAClaimMeetingThePersonPurgeWaitsAndThenFindsTheUploadGone(): void
    {
        $actor = Uuid::generate();
        $intent = $this->plantIntent($actor, UploadIntentState::Declared, claimed: false);

        $holder = $this->holder();
        $holder->beginTransaction();
        $this->anonymiseIntentsOn($actor);

        $this->assertTrue($this->waits(fn (): ClaimedUpload|Document => $this->claim($actor, $intent->id)));

        $holder->commit();

        $this->expectException(UploadGone::class);
        $this->claim($actor, $intent->id);
    }

    public function testThePersonPurgeMeetingAClaimInFlightWaitsAndThenExpiresTheStream(): void
    {
        $actor = Uuid::generate();
        $intent = $this->plantIntent($actor, UploadIntentState::Declared, claimed: false);

        $holder = $this->holder();
        $holder->beginTransaction();
        $this->lockIntentOn($intent->id);
        $holder->executeStatement(
            "UPDATE upload_intent SET state = 'streaming', claimed_at = NOW(), "
            . "lease_expires_at = NOW() + INTERVAL '90 seconds', upload_deadline_at = NOW() + INTERVAL '10 minutes' "
            . 'WHERE id = :id',
            ['id' => $intent->id],
        );

        $this->assertTrue($this->waits(fn (): int => $this->purge($actor)));

        $holder->commit();

        $this->purge($actor);
        $this->assertSame('expired', $this->stateOf($intent->id));
        $this->assertSame(ErasedPersonMarker::UUID, $this->intentConnection()->fetchOne(
            'SELECT actor_id FROM upload_intent WHERE id = :id',
            ['id' => $intent->id],
        ));
    }

    public function testACommitMeetingThePersonPurgeInFlightWaitsAndThenAborts(): void
    {
        $actor = Uuid::generate();
        $intent = $this->plantIntent($actor, UploadIntentState::Streaming);
        $this->mintKey($intent->scopeId);
        $claimed = new ClaimedUpload($intent->id, $intent->scopeId, 10, null, OriginalFilename::fromString('a.pdf'));

        $holder = $this->holder();
        $holder->beginTransaction();
        $this->anonymiseIntentsOn($actor);

        $this->assertTrue($this->waits(
            fn () => $this->service(CommitUpload::class)->commit($claimed, \hash('sha256', 'x')),
        ));

        $holder->commit();

        $this->expectException(UploadAborted::class);
        $this->service(CommitUpload::class)->commit($claimed, \hash('sha256', 'x'));
    }

    public function testTheReaperMeetingThePersonPurgeWaitsAndThenTombstonesTheMintedScope(): void
    {
        $actor = Uuid::generate();
        $intent = $this->plantIntent($actor, UploadIntentState::Streaming);
        $this->mintKey($intent->scopeId);

        $holder = $this->holder();
        $holder->beginTransaction();
        $this->anonymiseIntentsOn($actor);

        $this->assertTrue($this->waits(fn () => $this->service(ReapUploadIntents::class)->reapOne($intent->id)));

        $holder->commit();

        $this->assertTrue($this->service(ReapUploadIntents::class)->reapOne($intent->id));
        $this->assertSame('aborted-upload', $this->keyStateOf($intent->scopeId));
    }

    public function testThePersonPurgeMeetingTheReaperInFlightWaitsAndThenFindsNothingToRewrite(): void
    {
        $actor = Uuid::generate();
        $intent = $this->plantIntent($actor, UploadIntentState::Expired);

        $holder = $this->holder();
        $holder->beginTransaction();
        $this->lockIntentOn($intent->id);
        $holder->executeStatement('DELETE FROM upload_intent WHERE id = :id', ['id' => $intent->id]);

        $this->assertTrue($this->waits(fn (): int => $this->purge($actor)));

        $holder->commit();

        $this->assertSame(0, $this->purge($actor));
    }

    public function testTheRollForwardMeetingThePersonPurgeWaitsAndThenStillPromotes(): void
    {
        $actor = Uuid::generate();
        $document = $this->committedWithStagedObject($actor);

        $holder = $this->holder();
        $holder->beginTransaction();
        $holder->executeStatement(
            'UPDATE document SET uploader_id = CAST(:marker AS UUID) WHERE id IN '
            . '(SELECT id FROM document WHERE uploader_id = CAST(:actor AS UUID) ORDER BY id FOR UPDATE)',
            ['marker' => ErasedPersonMarker::UUID, 'actor' => $actor],
        );

        $this->assertTrue($this->waits(fn (): int => $this->rollForward()));

        $holder->commit();

        $this->assertSame(1, $this->rollForward());
        $this->assertNotNull($this->promotedAtOf($document->documentId()));
    }

    public function testThePersonPurgeMeetingTheRollForwardInFlightWaitsAndThenRewritesTheUploader(): void
    {
        $actor = Uuid::generate();
        $document = $this->committedWithStagedObject($actor);

        $holder = $this->holder();
        $holder->beginTransaction();
        $holder->executeStatement(
            'SELECT id FROM document WHERE id = :id FOR UPDATE',
            ['id' => $document->documentId()],
        );

        $this->assertTrue($this->waits(fn (): int => $this->purge($actor)));

        $holder->commit();

        $this->assertSame(2, $this->purge($actor), 'the uploader and the custody actor');
    }

    public function testTheRollForwardNeverWaitsOnAClaimACommitOrTheReaper(): void
    {
        $actor = Uuid::generate();
        $document = $this->committedWithStagedObject(Uuid::generate());
        $intent = $this->plantIntent($actor, UploadIntentState::Streaming);
        $this->mintKey($intent->scopeId);

        // A claim, a commit and a reaper in flight all hold the intent row; the commit and the reaper also hold
        // the keystore row and the commit an uncommitted document of its own. None of it is the roll-forward's.
        $holder = $this->holder();
        $holder->beginTransaction();
        $this->lockIntentOn($intent->id);
        $holder->executeStatement(
            'SELECT encryption_scope_id FROM dek_keystore WHERE encryption_scope_id = :s FOR UPDATE',
            ['s' => EncryptionScopeId::forDocument($intent->scopeId)->toString()],
        );
        $this->insertDocumentFor($holder, $intent->scopeId);

        $this->assertFalse($this->waits(fn (): int => $this->rollForward()), 'disjoint locks: nothing to wait on');
        $this->assertNotNull($this->promotedAtOf($document->documentId()));
    }

    private function claim(string $actor, string $intentId): ClaimedUpload|Document
    {
        $body = \fopen('php://memory', 'r');
        $this->assertIsResource($body);

        return $this->service(ClaimUpload::class)->claim(new UploadEvidenceCommand(
            uploadId: $intentId,
            actorId: $actor,
            contentLength: 10,
            filename: FilenameSubmission::fromQueryValues(['a.pdf']),
            body: $body,
        ));
    }

    private function purge(string $actor): int
    {
        return $this->service(TransactionManager::class)->transactional(
            fn (): int => $this->service(PurgeUserDocumentReferences::class)->eraseReferencesTo($actor),
        );
    }

    private function rollForward(): int
    {
        return $this->service(RollForwardPromotions::class)->run(UploadLimits::ROLL_FORWARD_AGE_SECONDS, 10_000);
    }

    private function lockIntentOn(string $intentId): void
    {
        $this->holder()->executeStatement(
            'SELECT id FROM upload_intent WHERE id = :id FOR UPDATE',
            ['id' => $intentId],
        );
    }

    /** The purge's intent statement, as the holder runs it inside its transaction. */
    private function anonymiseIntentsOn(string $actor): void
    {
        $this->holder()->executeStatement(
            'UPDATE upload_intent SET actor_id = CAST(:marker AS UUID), key_hash = NULL, lease_expires_at = NOW(), '
            . "state = 'expired' WHERE id IN "
            . '(SELECT id FROM upload_intent WHERE actor_id = CAST(:actor AS UUID) ORDER BY id FOR UPDATE)',
            ['marker' => ErasedPersonMarker::UUID, 'actor' => $actor],
        );
    }

    private function committedWithStagedObject(string $uploader): Document
    {
        $scope = RandomUuid::v4()->toRfc4122();
        $this->scopes[] = $scope;
        $this->service(DocumentStorage::class)->stageWith($scope, static function ($sink): void {
            \fwrite($sink, 'sealed');
        });
        $document = $this->service(ReceiveDocument::class)->receive($scope, $uploader, \hash('sha256', 'sealed'), 6);
        $this->intentConnection()->executeStatement(
            "UPDATE document SET next_scan_at = NOW() - INTERVAL '1 hour' WHERE id = :id",
            ['id' => $document->documentId()],
        );

        return $document;
    }

    private function stateOf(string $intentId): mixed
    {
        return $this->intentConnection()->fetchOne(
            'SELECT state FROM upload_intent WHERE id = :id',
            ['id' => $intentId],
        );
    }

    private function promotedAtOf(string $documentId): mixed
    {
        return $this->intentConnection()->fetchOne(
            'SELECT promoted_at FROM document WHERE id = :id',
            ['id' => $documentId],
        );
    }
}
