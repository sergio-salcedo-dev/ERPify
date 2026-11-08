<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Documents\Document;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Erpify\Documents\Document\Application\Upload\ClaimUpload;
use Erpify\Documents\Document\Application\Upload\Command\DeclareUploadCommand;
use Erpify\Documents\Document\Application\Upload\Command\UploadEvidenceCommand;
use Erpify\Documents\Document\Application\Upload\CommitUpload;
use Erpify\Documents\Document\Application\Upload\DeclareUpload;
use Erpify\Documents\Document\Application\Upload\ExpireUpload;
use Erpify\Documents\Document\Application\Upload\PromoteDocument;
use Erpify\Documents\Document\Application\Upload\ReapUploadIntents;
use Erpify\Documents\Document\Application\Upload\UploadEvidence;
use Erpify\Documents\Document\Application\Upload\UploadTimeBudget;
use Erpify\Documents\Document\Domain\Exception\UploadAborted;
use Erpify\Documents\Document\Domain\FilenameSubmission;
use Erpify\Documents\Document\Domain\Repository\UploadIntentRepository;
use Erpify\Documents\Document\Domain\Storage\DocumentStorage;
use Erpify\Documents\Document\Domain\Storage\StorageArea;
use Erpify\Documents\Document\Domain\Upload\LegalBasis;
use Erpify\Documents\Document\Domain\Upload\RetentionReason;
use Erpify\Documents\Document\Domain\UploadLimits;
use Erpify\Shared\Crypto\Application\StreamEnvelopeEncryptor;
use Erpify\Shared\Crypto\Domain\EncryptionScopeId;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Functional\ResolvesContainerServices;
use Erpify\Tests\Support\Dig;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The heartbeat through the database, end to end: a stream that heartbeats on every read moves the intent's
 * lease past what the claim set, and a stream whose intent is expired from another connection while it is being
 * read stops at the next heartbeat and aborts, leaving no staged object and no document; so does one whose
 * deadline passes while it is read. The interval is reduced to zero by constructing the use case with it;
 * everything else is the container's own wiring.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversClass(UploadEvidence::class)]
final class UploadHeartbeatFunctionalTest extends KernelTestCase
{
    use ResolvesContainerServices;

    /** Above 30 x 256 KiB, so the deadline (size at the floor + 60 s) lies past a fresh 90 s lease. */
    private const int SIZE = 8 * 1_048_576;

    private ?Connection $outside = null;

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

        $this->outside?->close();
        parent::tearDown();
    }

    public function testEveryHeartbeatRenewsTheLeaseInTheDatabase(): void
    {
        [$actor, $upload] = $this->declared();

        $outcome = $this->useCase()->upload($this->command($actor, $upload, ScriptedBody::open(self::SIZE)));

        $row = $this->row($upload);
        $claimedAt = new DateTimeImmutable(Dig::string($row, 'claimed_at'));
        $lease = new DateTimeImmutable(Dig::string($row, 'lease_expires_at'));
        $this->scopes[] = Dig::string($row, 'scope_id');

        $this->assertSame('committed', Dig::value($row, 'state'));
        $this->assertSame($outcome->document->documentId(), Dig::value($row, 'document_id'));
        $this->assertGreaterThan(
            $claimedAt->modify(\sprintf('+%d seconds', UploadLimits::LEASE_SECONDS)),
            $lease,
            'the lease moved past the one the claim set: a heartbeat renewed it in a later transaction',
        );
    }

    public function testAnIntentExpiredMidStreamStopsTheWriterAtItsNextHeartbeat(): void
    {
        [$actor, $upload] = $this->declared();
        $expire = function () use ($upload): void {
            $this->outside()->executeStatement(
                "UPDATE upload_intent SET state = 'expired', key_hash = NULL, lease_expires_at = NOW() WHERE id = :id",
                ['id' => $upload],
            );
        };

        $aborted = null;

        try {
            $this->useCase()->upload($this->command($actor, $upload, ScriptedBody::open(self::SIZE, 3, $expire)));
        } catch (UploadAborted $uploadAborted) {
            $aborted = $uploadAborted;
        }

        $this->assertInstanceOf(UploadAborted::class, $aborted, 'filed although its intent expired mid-stream');

        $scope = Dig::string($this->row($upload), 'scope_id');
        $this->scopes[] = $scope;
        $this->assertFalse($this->service(DocumentStorage::class)->has(StorageArea::Staging, $scope));
        $this->assertFalse($this->outside()->fetchOne('SELECT 1 FROM document WHERE scope_id = :s', ['s' => $scope]));
    }

    /**
     * The deadline itself, with nothing else expiring the intent: it passes while the body is still arriving,
     * the next heartbeat refuses to renew past it, and the upload ends as every aborted one does — the staged
     * object deleted, the intent stamped `Expired` without its key, no document, and the reaper's tombstone.
     */
    public function testABodyStillStreamingWhenItsDeadlinePassesAbortsAndIsTombstoned(): void
    {
        [$actor, $upload] = $this->declared();
        $passDeadline = function () use ($upload): void {
            $this->outside()->executeStatement(
                "UPDATE upload_intent SET upload_deadline_at = NOW() - INTERVAL '1 second' WHERE id = :id",
                ['id' => $upload],
            );
        };

        $aborted = null;

        try {
            $this->useCase()->upload(
                $this->command($actor, $upload, ScriptedBody::open(self::SIZE, 3, $passDeadline)),
            );
        } catch (UploadAborted $uploadAborted) {
            $aborted = $uploadAborted;
        }

        $this->assertInstanceOf(UploadAborted::class, $aborted, 'filed although its deadline passed mid-stream');
        $this->assertSame('aborted-upload', $aborted->type());

        $row = $this->row($upload);
        $scope = Dig::string($row, 'scope_id');
        $this->scopes[] = $scope;
        $this->assertSame('expired', Dig::value($row, 'state'));
        $this->assertNull(Dig::value($row, 'key_hash'));
        $this->assertFalse($this->service(DocumentStorage::class)->has(StorageArea::Staging, $scope));
        $this->assertFalse($this->outside()->fetchOne('SELECT 1 FROM document WHERE scope_id = :s', ['s' => $scope]));

        $this->assertTrue($this->service(ReapUploadIntents::class)->reapOne($upload));

        $this->assertFalse($this->outside()->fetchOne('SELECT 1 FROM upload_intent WHERE id = :id', ['id' => $upload]));
        $this->assertSame(
            'aborted-upload',
            $this->outside()->fetchOne('SELECT destroy_reason FROM dek_keystore WHERE encryption_scope_id = :s', [
                's' => EncryptionScopeId::forDocument($scope)->toString(),
            ]),
        );
    }

    /**
     * @return array{string, string}
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

        return [$actor, (string) $outcome->intentId()];
    }

    /**
     * @param resource $body
     */
    private function command(string $actor, string $upload, $body): UploadEvidenceCommand
    {
        return new UploadEvidenceCommand(
            uploadId: $upload,
            actorId: $actor,
            contentLength: self::SIZE,
            filename: FilenameSubmission::fromQueryValues(['heartbeat.bin']),
            body: $body,
        );
    }

    private function useCase(): UploadEvidence
    {
        return new UploadEvidence(
            $this->service(ClaimUpload::class),
            $this->service(CommitUpload::class),
            $this->service(ExpireUpload::class),
            $this->service(PromoteDocument::class),
            $this->service(StreamEnvelopeEncryptor::class),
            $this->service(DocumentStorage::class),
            $this->service(UploadIntentRepository::class),
            $this->service(TransactionManager::class),
            $this->service(UploadTimeBudget::class),
            heartbeatIntervalSeconds: 0,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $upload): array
    {
        $row = $this->outside()->fetchAssociative('SELECT * FROM upload_intent WHERE id = :id', ['id' => $upload]);
        $this->assertIsArray($row);

        return $row;
    }

    private function outside(): Connection
    {
        if (!$this->outside instanceof Connection) {
            $this->outside = DriverManager::getConnection($this->service(Connection::class)->getParams());
        }

        return $this->outside;
    }
}
