<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Persistence\Doctrine;

use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Erpify\Documents\Document\Domain\Entity\UploadIntent;
use Erpify\Documents\Document\Domain\ErasedPersonMarker;
use Erpify\Documents\Document\Domain\Repository\UploadIntentRepository;
use Erpify\Documents\Document\Domain\Upload\UploadIntentState;
use Erpify\Shared\Uuid\Domain\Uuid;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use UnexpectedValueException;

/**
 * Upload-intent persistence by COMPOSITION: the ORM locks, reads and updates one intent; DBAL runs the insert
 * whose key conflict must not close the entity manager, the counts the caps read, and the two bulk statements
 * (the retention prune and the person purge) that must load nothing into the unit of work.
 *
 * **The insert resolves its own key conflict.** `INSERT … ON CONFLICT (key_hash) DO NOTHING` never raises:
 * a unique violation through the ORM would close the entity manager mid-transaction, and the declare step
 * still has to read the winner and answer it.
 *
 * **Advisory locks are transaction-scoped** (`pg_advisory_xact_lock`), keyed by `hashtextextended` of a
 * namespaced string so the claim and declare locks of one actor never collide with each other or with any
 * other user of advisory locks; they release at commit or rollback with nothing to forget.
 *
 * @SuppressWarnings("PHPMD.TooManyPublicMethods") one port, one adapter
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") the entity, its state, the manager, connection and hints
 */
#[AsAlias(UploadIntentRepository::class)]
final readonly class DoctrineUploadIntentRepository implements UploadIntentRepository
{
    private const string CLAIM_ACTOR_LOCK = 'documents.upload.claim.actor:';

    private const string CLAIM_GLOBAL_LOCK = 'documents.upload.claim.global';

    private const string DECLARE_ACTOR_LOCK = 'documents.upload.declare.actor:';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private Connection $connection,
    ) {
    }

    #[Override]
    public function now(): DateTimeImmutable
    {
        $now = $this->connection->fetchOne(
            "SELECT to_char(NOW() AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS.US\"Z\"')",
        );

        if (!\is_string($now)) {
            throw new UnexpectedValueException('The database clock did not answer.');
        }

        return new DateTimeImmutable($now);
    }

    #[Override]
    public function insertUnlessKeyTaken(UploadIntent $intent): bool
    {
        $inserted = $this->connection->executeStatement(
            'INSERT INTO upload_intent ('
            . 'id, actor_id, scope_id, document_id, predecessor_id, state, declared_size, declared_sha256, key_hash, '
            . 'retention_reason, legal_basis, review_at, created_at, ttl_expires_at'
            . ') VALUES ('
            . ':id, :actor_id, :scope_id, NULL, NULL, :state, :declared_size, :declared_sha256, :key_hash, '
            . ':retention_reason, :legal_basis, :review_at, :created_at, :ttl_expires_at'
            . ') ON CONFLICT (key_hash) DO NOTHING',
            [
                'id' => $intent->id(),
                'actor_id' => $intent->actorId(),
                'scope_id' => $intent->scopeId(),
                'state' => $intent->state()->value,
                'declared_size' => $intent->declaredSize(),
                'declared_sha256' => $intent->declaredSha256(),
                'key_hash' => $intent->keyHash(),
                'retention_reason' => $intent->retentionReason()->value,
                'legal_basis' => $intent->legalBasis()->value,
                'review_at' => $intent->reviewAt(),
                'created_at' => $intent->createdAt(),
                'ttl_expires_at' => $intent->ttlExpiresAt(),
            ],
            [
                'declared_size' => Types::INTEGER,
                'review_at' => Types::DATETIME_IMMUTABLE,
                'created_at' => Types::DATETIMETZ_IMMUTABLE,
                'ttl_expires_at' => Types::DATETIMETZ_IMMUTABLE,
            ],
        );

        return 1 === (int) $inserted;
    }

    #[Override]
    public function findByKeyHash(string $keyHash): ?UploadIntent
    {
        $id = $this->connection->fetchOne('SELECT id FROM upload_intent WHERE key_hash = :key_hash', [
            'key_hash' => $keyHash,
        ]);

        return \is_string($id) ? $this->read($id) : null;
    }

    #[Override]
    public function lockForUpdate(string $id): ?UploadIntent
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        // Re-hydrated from the locked row (HINT_REFRESH): the answer is the row as it stands under the lock, never
        // an instance an earlier read left in the identity map.
        $intent = $this->entityManager->createQueryBuilder()
            ->select('i')
            ->from(UploadIntent::class, 'i')
            ->where('i.id = :id')
            ->setParameter('id', \strtolower($id))
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult()
        ;

        return $intent instanceof UploadIntent ? $intent : null;
    }

    #[Override]
    public function save(UploadIntent $intent): void
    {
        $this->entityManager->persist($intent);
        $this->entityManager->flush();
    }

    #[Override]
    public function delete(UploadIntent $intent): void
    {
        $this->entityManager->remove($intent);
        $this->entityManager->flush();
    }

    #[Override]
    public function lockClaimSlots(string $actorId): void
    {
        RequiresActiveTransaction::ensure($this->connection, 'Taking the claim slots of an upload');
        $this->advisoryLock(self::CLAIM_ACTOR_LOCK . \strtolower($actorId));
        $this->advisoryLock(self::CLAIM_GLOBAL_LOCK);
    }

    #[Override]
    public function lockDeclareSlot(string $actorId): void
    {
        RequiresActiveTransaction::ensure($this->connection, 'Taking the declare slot of an actor');
        $this->advisoryLock(self::DECLARE_ACTOR_LOCK . \strtolower($actorId));
    }

    #[Override]
    public function countLiveStreamingFor(string $actorId, DateTimeImmutable $now): int
    {
        return $this->count(
            'state = :state AND actor_id = CAST(:actor_id AS UUID) '
            . 'AND lease_expires_at > :now AND upload_deadline_at > :now',
            ['state' => UploadIntentState::Streaming->value, 'actor_id' => \strtolower($actorId), 'now' => $now],
        );
    }

    #[Override]
    public function countLiveStreaming(DateTimeImmutable $now): int
    {
        return $this->count(
            'state = :state AND lease_expires_at > :now AND upload_deadline_at > :now',
            ['state' => UploadIntentState::Streaming->value, 'now' => $now],
        );
    }

    #[Override]
    public function countLiveDeclaredFor(string $actorId, DateTimeImmutable $now): int
    {
        return $this->count(
            'state = :state AND actor_id = CAST(:actor_id AS UUID) AND ttl_expires_at > :now',
            ['state' => UploadIntentState::Declared->value, 'actor_id' => \strtolower($actorId), 'now' => $now],
        );
    }

    #[Override]
    public function reapCandidates(int $limit, ?string $afterId = null): array
    {
        /** @var list<string> $ids */
        $ids = $this->connection->fetchFirstColumn(
            'SELECT id FROM upload_intent WHERE ('
            . '(state = :declared AND ttl_expires_at <= NOW()) '
            . 'OR (state = :streaming AND (lease_expires_at <= NOW() OR upload_deadline_at <= NOW())) '
            . 'OR state = :expired'
            . ') AND (CAST(:after AS UUID) IS NULL OR id > CAST(:after AS UUID)) '
            . 'ORDER BY id LIMIT :limit',
            [
                'declared' => UploadIntentState::Declared->value,
                'streaming' => UploadIntentState::Streaming->value,
                'expired' => UploadIntentState::Expired->value,
                'after' => $afterId,
                'limit' => $limit,
            ],
            ['limit' => Types::INTEGER],
        );

        return $ids;
    }

    #[Override]
    public function deleteCommittedBefore(DateTimeImmutable $cutoff, int $limit): int
    {
        RequiresActiveTransaction::ensure($this->connection, 'Pruning committed upload intents');

        return (int) $this->connection->executeStatement(
            'DELETE FROM upload_intent WHERE id IN ('
            . 'SELECT id FROM upload_intent WHERE state = :committed AND committed_at < :cutoff '
            . 'ORDER BY id LIMIT :limit FOR UPDATE SKIP LOCKED'
            . ')',
            ['committed' => UploadIntentState::Committed->value, 'cutoff' => $cutoff, 'limit' => $limit],
            ['cutoff' => Types::DATETIMETZ_IMMUTABLE, 'limit' => Types::INTEGER],
        );
    }

    #[Override]
    public function hasLiveIntentForScope(string $scopeId): bool
    {
        return 0 < $this->count(
            'scope_id = CAST(:scope_id AS UUID) AND state IN (:declared, :streaming)',
            [
                'scope_id' => \strtolower($scopeId),
                'declared' => UploadIntentState::Declared->value,
                'streaming' => UploadIntentState::Streaming->value,
            ],
        );
    }

    #[Override]
    public function anonymiseActor(string $subjectId): int
    {
        Uuid::ensure($subjectId);
        RequiresActiveTransaction::ensure($this->connection, 'Anonymising the actor of an upload intent');

        return (int) $this->connection->executeStatement(
            'UPDATE upload_intent SET '
            . 'actor_id = CAST(:marker AS UUID), '
            . 'key_hash = NULL, '
            . 'lease_expires_at = CASE WHEN state IN (:declared, :streaming) THEN NOW() ELSE lease_expires_at END, '
            . 'state = CASE WHEN state IN (:declared, :streaming) THEN :expired ELSE state END '
            . 'WHERE id IN ('
            . 'SELECT id FROM upload_intent WHERE actor_id = CAST(:subject_id AS UUID) ORDER BY id FOR UPDATE'
            . ')',
            [
                'marker' => ErasedPersonMarker::UUID,
                'subject_id' => \strtolower($subjectId),
                'declared' => UploadIntentState::Declared->value,
                'streaming' => UploadIntentState::Streaming->value,
                'expired' => UploadIntentState::Expired->value,
            ],
        );
    }

    private function read(string $id): ?UploadIntent
    {
        $intent = $this->entityManager->createQueryBuilder()
            ->select('i')
            ->from(UploadIntent::class, 'i')
            ->where('i.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult()
        ;

        return $intent instanceof UploadIntent ? $intent : null;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function count(string $where, array $parameters): int
    {
        $types = \array_map(
            static fn (mixed $value): ?string => $value instanceof DateTimeInterface
                ? Types::DATETIMETZ_IMMUTABLE
                : null,
            $parameters,
        );

        $count = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM upload_intent WHERE ' . $where,
            $parameters,
            \array_filter($types, static fn (?string $type): bool => null !== $type),
        );

        if (!\is_numeric($count)) {
            throw new UnexpectedValueException('An upload-intent count was not a number.');
        }

        return (int) $count;
    }

    private function advisoryLock(string $name): void
    {
        $this->connection->executeStatement('SELECT pg_advisory_xact_lock(hashtextextended(:name, 0))', [
            'name' => $name,
        ]);
    }
}
