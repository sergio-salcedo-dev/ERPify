<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Persistence\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Erpify\Documents\Document\Domain\DocumentState;
use Erpify\Documents\Document\Domain\Entity\Document;
use Erpify\Documents\Document\Domain\Entity\DocumentCustody;
use Erpify\Documents\Document\Domain\ErasedPersonMarker;
use Erpify\Documents\Document\Domain\Exception\DocumentNotFound;
use Erpify\Documents\Document\Domain\Repository\DocumentRepository;
use Erpify\Shared\Persistence\Domain\Exception\ConcurrentUniqueWrite;
use Erpify\Shared\Uuid\Domain\Uuid;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Throwable;

/**
 * Document persistence by COMPOSITION: the ORM inserts and locks the aggregate, plain DBAL runs the one bulk
 * statement the erasure needs.
 *
 * The anonymisation is DBAL on purpose and loads nothing: an ORM read of the subject's documents would leave
 * managed instances holding the subject's id in the identity map after the rewrite, and a bulk DQL `UPDATE`
 * does not refresh them. Tests that exercise it read the result through DBAL for the same reason.
 *
 * **The rows are taken in `id` order, and the clause is not decoration.** The subject's documents are locked by
 * the inner `SELECT … ORDER BY id FOR UPDATE` before the outer `UPDATE` rewrites any of them, so two
 * concurrent erasures reaching overlapping rows (a document one of them uploaded and the other is the actor
 * of) acquire them in one sequence and one waits instead of deadlocking. The custody pass runs after this one,
 * in the same transaction: documents, then custody rows, the order the context declares.
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") the aggregate, its custody row, the manager, the connection, the
 *                                                   query hint and the three errors it translates
 */
#[AsAlias(DocumentRepository::class)]
final readonly class DoctrineDocumentRepository implements DocumentRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private Connection $connection,
        private DbalDocumentPromotions $promotions,
    ) {
    }

    #[Override]
    public function add(Document $document, DocumentCustody $firstCustody): void
    {
        try {
            $this->entityManager->persist($document);
            $this->entityManager->persist($firstCustody);
            $this->entityManager->flush();
        } catch (Throwable $throwable) {
            if (ViolatedUniqueConstraint::is($throwable, Document::IDEMPOTENCY_KEY_CONSTRAINT)) {
                throw ConcurrentUniqueWrite::onWrite('document');
            }

            throw $throwable;
        }
    }

    #[Override]
    public function lockForUpdate(string $id): Document
    {
        Uuid::ensure($id);

        // A DQL lock query that re-hydrates from the locked row (HINT_REFRESH), as the identity module's lock finders
        // do: the answer is the row as it stands under the lock, never an in-memory snapshot read before it. The
        // functional test files the document through this manager and moves it from a second connection first.
        $document = $this->entityManager->createQueryBuilder()
            ->select('d')
            ->from(Document::class, 'd')
            ->where('d.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult()
        ;

        if (!$document instanceof Document) {
            throw DocumentNotFound::create();
        }

        return $document;
    }

    #[Override]
    public function anonymiseUploader(string $subjectId): int
    {
        Uuid::ensure($subjectId);
        RequiresActiveTransaction::ensure($this->connection, 'Anonymising the uploader of a document');

        return (int) $this->connection->executeStatement(
            'UPDATE document '
            . 'SET uploader_id = CAST(:marker AS UUID), idempotency_key_hash = NULL '
            . 'WHERE id IN ('
            . 'SELECT id FROM document WHERE uploader_id = CAST(:subject_id AS UUID) ORDER BY id FOR UPDATE'
            . ')',
            [
                'marker' => ErasedPersonMarker::UUID,
                'subject_id' => $subjectId,
            ],
        );
    }

    #[Override]
    public function findById(string $id): ?Document
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        return $this->readOne('d.id = :value', \strtolower($id));
    }

    #[Override]
    public function findByIdempotencyKeyHash(string $keyHash): ?Document
    {
        return $this->readOne('d.idempotencyKeyHash = :value', $keyHash);
    }

    #[Override]
    public function existsForScope(string $scopeId): bool
    {
        return false !== $this->connection->fetchOne(
            'SELECT 1 FROM document WHERE scope_id = CAST(:scope_id AS UUID)',
            ['scope_id' => \strtolower($scopeId)],
        );
    }

    #[Override]
    public function markPromoted(string $id): bool
    {
        return $this->promotions->markPromoted($id);
    }

    #[Override]
    public function awaitingPromotion(int $olderThanSeconds, int $limit): array
    {
        /** @var list<string> $ids */
        $ids = $this->connection->fetchFirstColumn(
            'SELECT id FROM document WHERE state = :received AND promoted_at IS NULL '
            // `next_scan_at` is the insert's transaction time on the database clock, and nothing moves it before
            // promotion.
            . 'AND next_scan_at <= NOW() - make_interval(secs => :age) ORDER BY id LIMIT :limit',
            ['received' => DocumentState::Received->value, 'age' => $olderThanSeconds, 'limit' => $limit],
            ['age' => Types::INTEGER, 'limit' => Types::INTEGER],
        );

        return $ids;
    }

    /**
     * A read that re-hydrates from the row (HINT_REFRESH), so a document an earlier statement rewrote in bulk is
     * never answered from a stale instance in the identity map.
     */
    private function readOne(string $condition, string $value): ?Document
    {
        $document = $this->entityManager->createQueryBuilder()
            ->select('d')
            ->from(Document::class, 'd')
            ->where($condition)
            ->setParameter('value', $value)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult()
        ;

        return $document instanceof Document ? $document : null;
    }
}
