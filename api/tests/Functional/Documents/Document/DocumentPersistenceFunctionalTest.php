<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Documents\Document;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Documents\Document\Application\AppendCustody;
use Erpify\Documents\Document\Application\ReceiveDocument;
use Erpify\Documents\Document\Domain\CustodyEvent;
use Erpify\Documents\Document\Domain\DocumentState;
use Erpify\Documents\Document\Domain\Entity\Document;
use Erpify\Documents\Document\Domain\Entity\DocumentCustody;
use Erpify\Documents\Document\Domain\Exception\CustodySequenceCollision;
use Erpify\Documents\Document\Domain\Exception\DocumentNotFound;
use Erpify\Documents\Document\Domain\Repository\DocumentCustodyRepository;
use Erpify\Documents\Document\Domain\Repository\DocumentRepository;
use Erpify\Documents\Document\Infrastructure\Persistence\Doctrine\DoctrineDocumentCustodyRepository;
use Erpify\Documents\Document\Infrastructure\Persistence\Doctrine\DoctrineDocumentRepository;
use Erpify\Documents\Document\Infrastructure\Persistence\Doctrine\ViolatedUniqueConstraint;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Shared\Persistence\Domain\Exception\ConcurrentUniqueWrite;
use Erpify\Shared\Persistence\Domain\Exception\TransientTransactionFailure;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Functional\ResolvesContainerServices;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid as RandomUuid;

/**
 * The Documents write path against real Postgres: the pair of rows filed together, the sequence number
 * computed under the lock, and the two unique constraints classified by NAME against the real driver text.
 *
 * A race cannot be driven from one PHPUnit process here (no `pcntl`, no asynchronous query), and it does not
 * need to be to test the part that is the application's. Two things are measured instead:
 *
 *   - that the lock is really held while the append runs, from a second connection that asks for the same row
 *     with `NOWAIT`, so "concurrent appenders are serialised" is a fact about the database and not an
 *     inference from the SQL;
 *   - that a LOST race is survived, by making the sequence number stale once — the exact state a missing lock
 *     would produce — so the real constraint fires under its real name, the whole transaction re-runs on a
 *     fresh manager, and the committed rows stay gap-free.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 * @SuppressWarnings("PHPMD.ExcessiveClassLength") the write path's promises, one case each, share one fixture
 * @SuppressWarnings("PHPMD.TooManyPublicMethods") one case per promise of the write path: the sequence, its lock, its
 *                                                 retry, its rollback and the two constraints
 */
#[CoversClass(ReceiveDocument::class)]
#[CoversClass(AppendCustody::class)]
#[CoversClass(DoctrineDocumentRepository::class)]
#[CoversClass(DoctrineDocumentCustodyRepository::class)]
#[CoversClass(ViolatedUniqueConstraint::class)]
final class DocumentPersistenceFunctionalTest extends KernelTestCase
{
    use PlantsDocumentRows;
    use ResolvesContainerServices;

    private const string DIGEST = 'a3b1c9d7e5f3a1b9c7d5e3f1a9b7c5d3e1f9a7b5c3d1e9f7a5b3c1d9e7f5a3b1';

    private ?Connection $outside = null;

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->emptyDocumentTables();
    }

    protected function tearDown(): void
    {
        $this->emptyDocumentTables();
        $this->outside?->close();

        parent::tearDown();
    }

    #[Test]
    public function aReceivedDocumentAndItsFirstCustodyRowAreCommittedTogether(): void
    {
        $uploader = Uuid::generate();

        $document = $this->receiveDocument()
            ->receive(RandomUuid::v4()->toRfc4122(), $uploader, self::DIGEST, 4096)
        ;

        $id = (string) $document->getId();
        $row = $this->documentRow($id);
        $this->assertSame($uploader, $row['uploader_id']);
        $this->assertSame(DocumentState::Received->value, $row['state']);
        $this->assertSame(self::DIGEST, $row['digest']);
        $this->assertSame([['seq' => 1, 'actor_id' => $uploader]], $this->custodyRows($id));
        $this->assertSame(
            [$id, null],
            [
                $this->connection()->fetchOne(
                    'SELECT series_id FROM document WHERE id = CAST(:id AS UUID)',
                    ['id' => $id],
                ),
                $this->connection()->fetchOne(
                    'SELECT predecessor_id FROM document WHERE id = CAST(:id AS UUID)',
                    ['id' => $id],
                ),
            ],
            'a first version is its own series and has no predecessor',
        );
    }

    #[Test]
    public function aFailureAfterBothInsertsLeavesNeitherRow(): void
    {
        $document = Document::receive(
            Uuid::generate(),
            RandomUuid::v4()->toRfc4122(),
            Uuid::generate(),
            self::DIGEST,
            10,
        );
        $first = DocumentCustody::record(
            Uuid::generate(),
            (string) $document->getId(),
            1,
            CustodyEvent::Received,
            $document->uploaderId(),
        );

        try {
            $this->service(TransactionManager::class)->transactional(function () use ($document, $first): never {
                $this->service(DocumentRepository::class)->add($document, $first);

                throw new RuntimeException('forced rollback after the flush');
            });
        } catch (RuntimeException) {
            // Expected: the rows below are what proves the failure rolled both inserts back.
        }

        $this->assertSame(0, $this->rowsIn('document'));
        $this->assertSame(0, $this->rowsIn('document_custody'));
    }

    #[Test]
    public function aSecondDocumentUnderTheSameIdempotencyKeyHashIsAConflictAndTheManagerSurvivesIt(): void
    {
        $receive = $this->receiveDocument();
        $uploader = Uuid::generate();
        $hash = \hash('sha256', 'actor-scoped key');
        $receive->receive(RandomUuid::v4()->toRfc4122(), $uploader, self::DIGEST, 10, $hash);

        try {
            $receive->receive(RandomUuid::v4()->toRfc4122(), $uploader, self::DIGEST, 10, $hash);
            $this->fail('Expected ConcurrentUniqueWrite.');
        } catch (ConcurrentUniqueWrite $concurrentUniqueWrite) {
            $this->assertNull($concurrentUniqueWrite->getPrevious(), 'the driver text names the key: not chained');
        }

        $this->assertSame(1, $this->rowsIn('document'));
        $this->assertSame(1, $this->rowsIn('document_custody'));

        // A conflict must not strand the next request on a closed manager.
        $receive->receive(RandomUuid::v4()->toRfc4122(), $uploader, self::DIGEST, 10, \hash('sha256', 'another'));
        $this->assertSame(2, $this->rowsIn('document'));
    }

    #[Test]
    public function nullIdempotencyKeyHashesNeverCollide(): void
    {
        $receive = $this->receiveDocument();
        $uploader = Uuid::generate();

        $receive->receive(RandomUuid::v4()->toRfc4122(), $uploader, self::DIGEST, 10);
        $receive->receive(RandomUuid::v4()->toRfc4122(), $uploader, self::DIGEST, 10);

        $this->assertSame(2, $this->rowsIn('document'));
    }

    #[Test]
    public function appendsToOneDocumentAreNumberedFromOneWithNoGapAndEachDocumentCountsOnItsOwn(): void
    {
        [$a, $b] = [$this->received(), $this->received()];
        $append = $this->appendCustody();
        $actors = [Uuid::generate(), Uuid::generate()];

        foreach ([$a, $b, $a, $a, $b] as $turn => $documentId) {
            $append->append($documentId, CustodyEvent::Received, $actors[$turn % 2]);
        }

        $this->assertSame([1, 2, 3, 4], \array_column($this->custodyRows($a), 'seq'));
        $this->assertSame([1, 2, 3], \array_column($this->custodyRows($b), 'seq'));
    }

    #[Test]
    public function aLockIsHeldOnTheDocumentRowWhileTheAppendRunsAndItsContenderCannotInterleave(): void
    {
        $documentId = $this->received();
        $observed = [];

        $probing = new ProbingDocumentCustodyRepository(
            $this->service(DocumentCustodyRepository::class),
            function () use ($documentId, &$observed): void {
                $observed['lockedAgainstARow'] = $this->isLocked($documentId);
                $observed['insertBlocked'] = $this->aCompetingInsertIsRefusedForWaiting($documentId);
            },
        );

        $useCase = new AppendCustody(
            $this->service(DocumentRepository::class),
            $probing,
            $this->service(TransactionManager::class),
        );
        $useCase->append($documentId, CustodyEvent::Received, Uuid::generate());

        $this->assertSame(
            ['lockedAgainstARow' => true, 'insertBlocked' => true],
            $observed,
            'while an appender holds the document, no other connection can lock it or append to its custody',
        );
        $this->assertSame([1, 2], \array_column($this->custodyRows($documentId), 'seq'));
    }

    #[Test]
    public function aLostRaceIsSurvivedByRerunningTheWholeTransactionWithADistinctNumberAndNoGap(): void
    {
        $documentId = $this->received();
        $actor = Uuid::generate();
        $this->appendCustody()->append($documentId, CustodyEvent::Received, $actor);

        // One stale read is exactly what a missing lock would hand an appender: it believes the next number is
        // the one already committed, so the real INSERT meets the real `UNIQUE (document_id, seq)`.
        $probing = new ProbingDocumentCustodyRepository(
            $this->service(DocumentCustodyRepository::class),
            staleReads: 1,
        );
        $useCase = new AppendCustody(
            $this->service(DocumentRepository::class),
            $probing,
            $this->service(TransactionManager::class),
        );

        $appended = $useCase->append($documentId, CustodyEvent::Received, $actor);

        $this->assertSame(1, $probing->collisionsRaised, 'the real constraint fired and was classified by its name');
        $this->assertSame(3, $appended->seq());
        $this->assertSame([1, 2, 3], \array_column($this->custodyRows($documentId), 'seq'), 'no duplicate, no gap');
    }

    #[Test]
    public function aRaceLostOnEveryAttemptEndsInATransientFailureAndLeavesNoPartialRow(): void
    {
        $documentId = $this->received();
        $probing = new ProbingDocumentCustodyRepository(
            $this->service(DocumentCustodyRepository::class),
            staleReads: 99,
        );
        $useCase = new AppendCustody(
            $this->service(DocumentRepository::class),
            $probing,
            $this->service(TransactionManager::class),
        );

        try {
            $useCase->append($documentId, CustodyEvent::Received, Uuid::generate());
            $this->fail('Expected TransientTransactionFailure.');
        } catch (TransientTransactionFailure $transientTransactionFailure) {
            $this->assertInstanceOf(CustodySequenceCollision::class, $transientTransactionFailure->getPrevious());
        }

        $this->assertSame(3, $probing->collisionsRaised, 'bounded at three attempts');
        $this->assertSame([1], \array_column($this->custodyRows($documentId), 'seq'));
    }

    #[Test]
    public function aForcedRollbackAfterAnAppendLeavesNoPartialCustodyRow(): void
    {
        $documentId = $this->received();
        $probing = new ProbingDocumentCustodyRepository(
            $this->service(DocumentCustodyRepository::class),
            afterAppend: static function (): never {
                throw new RuntimeException('forced rollback after the flush');
            },
        );
        $useCase = new AppendCustody(
            $this->service(DocumentRepository::class),
            $probing,
            $this->service(TransactionManager::class),
        );

        try {
            $useCase->append($documentId, CustodyEvent::Received, Uuid::generate());
            $this->fail('Expected the forced failure.');
        } catch (RuntimeException) {
            $this->assertSame([1], \array_column($this->custodyRows($documentId), 'seq'));
        }

        // And the manager is usable afterwards, with the number the rolled-back attempt would have taken.
        $next = $this->appendCustody()->append($documentId, CustodyEvent::Received, Uuid::generate());
        $this->assertSame(2, $next->seq());
    }

    #[Test]
    public function aLockOnAManagedDocumentAnswersTheRowAsAnotherConnectionLeftIt(): void
    {
        // The document is MANAGED here (filed through this entity manager) and a second connection then moves
        // it on. `find(…, PESSIMISTIC_WRITE)` would lock the row and still hand back the in-memory snapshot, so
        // a compare-and-set would run against the stale state; the refresh is what makes the lock mean something.
        $filed = $this->receiveDocument()
            ->receive(RandomUuid::v4()->toRfc4122(), Uuid::generate(), self::DIGEST, 10)
        ;
        $documentId = (string) $filed->getId();
        $this->assertTrue(
            $this->service(EntityManagerInterface::class)->contains($filed),
            "the premise: the document is managed, so only the refresh can show the other connection's write",
        );
        $this->outsideConnection()->executeStatement(
            "UPDATE document SET state = 'ready' WHERE id = CAST(:id AS UUID)",
            ['id' => $documentId],
        );

        $state = $this->service(TransactionManager::class)->transactional(
            fn (): DocumentState => $this->service(DocumentRepository::class)->lockForUpdate($documentId)->state(),
        );

        $this->assertSame(DocumentState::Ready, $state);
    }

    #[Test]
    public function appendingToAnAbsentDocumentIsNotFound(): void
    {
        $this->expectException(DocumentNotFound::class);

        $this->appendCustody()->append(Uuid::generate(), CustodyEvent::Received, Uuid::generate());
    }

    #[Test]
    public function theRealDriverMessageIsRecognisedByConstraintNameAndByNoOtherName(): void
    {
        $documentId = $this->received();
        $hash = \hash('sha256', 'key');
        $this->plantDocument(Uuid::generate(), idempotencyKeyHash: $hash);

        $cases = [
            'sequence' => fn (): string => $this->plantCustody($documentId, 1, Uuid::generate()),
            'idempotency' => fn (): string => $this->plantDocument(Uuid::generate(), idempotencyKeyHash: $hash),
        ];
        $verdicts = [];

        foreach ($cases as $label => $violation) {
            try {
                $violation();
                $this->fail(\sprintf('Expected a unique violation for %s.', $label));
            } catch (UniqueConstraintViolationException $exception) {
                $verdicts[$label] = [
                    'sequence' => ViolatedUniqueConstraint::is($exception, DocumentCustody::SEQUENCE_CONSTRAINT),
                    'idempotency' => ViolatedUniqueConstraint::is($exception, Document::IDEMPOTENCY_KEY_CONSTRAINT),
                    'scope' => ViolatedUniqueConstraint::is($exception, Document::SCOPE_ID_CONSTRAINT),
                ];
            }
        }

        $this->assertSame(
            [
                'sequence' => ['sequence' => true, 'idempotency' => false, 'scope' => false],
                'idempotency' => ['sequence' => false, 'idempotency' => true, 'scope' => false],
            ],
            $verdicts,
        );
    }

    /**
     * Built from the container's production adapters rather than fetched: nothing consumes the use cases yet,
     * so the container drops them, and the upload flow that will wire them is a later story.
     */
    private function receiveDocument(): ReceiveDocument
    {
        return new ReceiveDocument(
            $this->service(DocumentRepository::class),
            $this->service(TransactionManager::class),
        );
    }

    private function appendCustody(): AppendCustody
    {
        return new AppendCustody(
            $this->service(DocumentRepository::class),
            $this->service(DocumentCustodyRepository::class),
            $this->service(TransactionManager::class),
        );
    }

    private function received(): string
    {
        return (string) $this->receiveDocument()
            ->receive(RandomUuid::v4()->toRfc4122(), Uuid::generate(), self::DIGEST, 10)
            ->getId()
        ;
    }

    private function isLocked(string $documentId): bool
    {
        $probe = $this->outsideConnection();
        $probe->beginTransaction();

        try {
            $probe->fetchOne(
                'SELECT id FROM document WHERE id = CAST(:id AS UUID) FOR UPDATE NOWAIT',
                ['id' => $documentId],
            );

            return false;
        } catch (DriverException $driverException) {
            $this->assertSame('55P03', $driverException->getSQLState());

            return true;
        } finally {
            if ($probe->isTransactionActive()) {
                $probe->rollBack();
            }
        }
    }

    /**
     * A custody insert takes a `KEY SHARE` lock on its document through the foreign key, which conflicts with
     * the appender's `FOR UPDATE`; the short `lock_timeout` turns that wait into an answer.
     */
    private function aCompetingInsertIsRefusedForWaiting(string $documentId): bool
    {
        $probe = $this->outsideConnection();
        $probe->beginTransaction();

        try {
            $probe->executeStatement("SET LOCAL lock_timeout = '150ms'");
            $probe->executeStatement(
                'INSERT INTO document_custody (id, document_id, seq, event, actor_id, created_at) '
                . "VALUES (:id, :document, 99, 'received', :actor, NOW())",
                ['id' => Uuid::generate(), 'document' => $documentId, 'actor' => Uuid::generate()],
            );

            return false;
        } catch (DriverException $driverException) {
            $this->assertSame('55P03', $driverException->getSQLState());

            return true;
        } finally {
            if ($probe->isTransactionActive()) {
                $probe->rollBack();
            }
        }
    }

    private function outsideConnection(): Connection
    {
        if (!$this->outside instanceof Connection) {
            $entityManager = $this->service(EntityManagerInterface::class);
            $this->outside = DriverManager::getConnection($entityManager->getConnection()->getParams());
        }

        return $this->outside;
    }
}
