<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Documents\Document;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Erpify\Documents\Document\Application\PurgeUserDocumentReferences;
use Erpify\Documents\Document\Application\Upload\ClaimedUpload;
use Erpify\Documents\Document\Application\Upload\CommitUpload;
use Erpify\Documents\Document\Application\Upload\ReapUploadIntents;
use Erpify\Documents\Document\Domain\Exception\UploadAborted;
use Erpify\Documents\Document\Domain\OriginalFilename;
use Erpify\Documents\Document\Domain\Upload\UploadIntentState;
use Erpify\Shared\Crypto\Domain\EncryptionScopeId;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Functional\ResolvesContainerServices;
use Erpify\Tests\Support\Dig;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Throwable;

/**
 * The intent row is the one lock the commit and the reaper share, and the person purge takes it first too.
 * Each pair is interleaved for real: a second connection holds what one side holds mid-transaction, the other
 * side is shown to WAIT on it (a short `lock_timeout` turns the wait into an answer), and once the holder
 * commits the waiter rechecks under the lock and takes the one outcome left to it. Exactly one wins; the loser
 * stands down; nothing deadlocks, because every side takes the intent row first.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversClass(CommitUpload::class)]
#[CoversClass(ReapUploadIntents::class)]
#[CoversClass(PurgeUserDocumentReferences::class)]
final class UploadLockOrderFunctionalTest extends KernelTestCase
{
    use PlantsUploadIntents;
    use ResolvesContainerServices;

    private ?Connection $holder = null;

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();
    }

    protected function tearDown(): void
    {
        if ($this->holder instanceof Connection && $this->holder->isTransactionActive()) {
            $this->holder->rollBack();
        }

        $this->holder?->close();
        $this->intentConnection()->executeStatement('SET lock_timeout = 0');
        parent::tearDown();
    }

    public function testAReaperMeetingACommitInFlightWaitsAndThenStandsDown(): void
    {
        $intent = $this->plantIntent(Uuid::generate(), UploadIntentState::Streaming, leaseOffset: -1);
        $this->mintKey($intent->scopeId);

        // The commit, mid-transaction: it holds the intent row, files the row and marks the intent committed.
        $holder = $this->holder();
        $holder->beginTransaction();
        $holder->executeStatement('SELECT id FROM upload_intent WHERE id = :id FOR UPDATE', ['id' => $intent->id]);

        $documentId = $this->insertDocumentFor($holder, $intent->scopeId);
        $holder->executeStatement(
            "UPDATE upload_intent SET state = 'committed', document_id = :d, committed_at = NOW() WHERE id = :id",
            ['d' => $documentId, 'id' => $intent->id],
        );

        $this->assertTrue($this->waits(fn () => $this->service(ReapUploadIntents::class)->reapOne($intent->id)));

        $holder->commit();

        $this->assertFalse($this->service(ReapUploadIntents::class)->reapOne($intent->id), 'the loser stands down');
        $this->assertSame('live', $this->keyStateOf($intent->scopeId), 'a committed scope is never destroyed');
        $this->assertSame('committed', $this->intentConnection()->fetchOne(
            'SELECT state FROM upload_intent WHERE id = :id',
            ['id' => $intent->id],
        ));
    }

    public function testACommitMeetingAReaperInFlightWaitsAndThenAborts(): void
    {
        $intent = $this->plantIntent(Uuid::generate(), UploadIntentState::Streaming);
        $this->mintKey($intent->scopeId);
        $claimed = new ClaimedUpload($intent->id, $intent->scopeId, 10, null, OriginalFilename::fromString('a.pdf'));

        // The reaper, mid-transaction: it holds the intent row and the keystore row, tombstones and deletes.
        $holder = $this->holder();
        $holder->beginTransaction();
        $holder->executeStatement('SELECT id FROM upload_intent WHERE id = :id FOR UPDATE', ['id' => $intent->id]);
        $holder->executeStatement(
            "UPDATE dek_keystore SET destroyed_at = NOW(), wrapped_dek = NULL, destroy_reason = 'aborted-upload' "
            . 'WHERE encryption_scope_id = :scope',
            ['scope' => EncryptionScopeId::forDocument($intent->scopeId)->toString()],
        );
        $holder->executeStatement('DELETE FROM upload_intent WHERE id = :id', ['id' => $intent->id]);

        $this->assertTrue($this->waits(fn () => $this->service(CommitUpload::class)->commit(
            $claimed,
            \hash('sha256', 'x'),
        )));

        $holder->commit();

        $this->expectException(UploadAborted::class);

        try {
            $this->service(CommitUpload::class)->commit($claimed, \hash('sha256', 'x'));
        } finally {
            $this->assertSame(0, Dig::int([$this->intentConnection()->fetchOne(
                'SELECT COUNT(*) FROM document WHERE scope_id = :s',
                ['s' => $intent->scopeId],
            )], 0));
        }
    }

    public function testThePersonPurgeTakesTheIntentRowFirstSoItWaitsOnACommitInFlight(): void
    {
        $actor = Uuid::generate();
        $intent = $this->plantIntent($actor, UploadIntentState::Streaming);

        $holder = $this->holder();
        $holder->beginTransaction();
        $holder->executeStatement('SELECT id FROM upload_intent WHERE id = :id FOR UPDATE', ['id' => $intent->id]);

        $this->assertTrue($this->waits(fn () => $this->service(TransactionManager::class)->transactional(
            fn (): int => $this->service(PurgeUserDocumentReferences::class)->eraseReferencesTo($actor),
        )));

        $holder->commit();

        $this->service(TransactionManager::class)->transactional(
            fn (): int => $this->service(PurgeUserDocumentReferences::class)->eraseReferencesTo($actor),
        );
        $this->assertSame('expired', $this->intentConnection()->fetchOne(
            'SELECT state FROM upload_intent WHERE id = :id',
            ['id' => $intent->id],
        ));
    }

    /**
     * Whether `$operation` blocks on a lock held by the other connection, rather than completing or failing for
     * another reason: it runs under a short lock timeout and must fail with SQLSTATE 55P03 in its chain.
     */
    private function waits(callable $operation): bool
    {
        $this->intentConnection()->executeStatement("SET lock_timeout = '200ms'");

        try {
            $operation();

            return false;
        } catch (Throwable $throwable) {
            for ($cause = $throwable; $cause instanceof Throwable; $cause = $cause->getPrevious()) {
                if (
                    \str_contains($cause->getMessage(), '55P03') || \str_contains(
                        $cause->getMessage(),
                        'lock timeout',
                    )
                ) {
                    return true;
                }
            }

            throw $throwable;
        } finally {
            $this->intentConnection()->executeStatement('SET lock_timeout = 0');
        }
    }

    private function insertDocumentFor(Connection $connection, string $scopeId): string
    {
        $id = Uuid::generate();
        $connection->executeStatement(
            'INSERT INTO document (id, scope_id, series_id, uploader_id, state, digest, size_bytes, next_scan_at, '
            . 'created_at, updated_at) '
            . "VALUES (:id, :scope, :id, :uploader, 'received', :digest, 10, NOW(), NOW(), NOW())",
            ['id' => $id, 'scope' => $scopeId, 'uploader' => Uuid::generate(), 'digest' => \hash('sha256', $id)],
        );

        return $id;
    }

    private function holder(): Connection
    {
        return $this->holder ??= DriverManager::getConnection($this->intentConnection()->getParams());
    }
}
