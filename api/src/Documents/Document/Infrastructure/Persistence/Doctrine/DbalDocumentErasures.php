<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Persistence\Doctrine;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Erpify\Documents\Document\Domain\CustodyEvent;
use Erpify\Documents\Document\Domain\DocumentState;
use Erpify\Documents\Document\Domain\Erasure\DocumentErasures;
use Erpify\Documents\Document\Domain\Erasure\ErasedObject;
use Erpify\Documents\Document\Domain\Erasure\ErasureLimits;
use Erpify\Shared\Uuid\Domain\Uuid;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * The erasure's statements on `document`, one per question and every judgement of time the database's. Kept apart from
 * the repository (whose single `UPDATE document` the person purge's lock-order gate reads alone) and from
 * {@see DbalDocumentScanLeases}, whose compare-and-sets never match an `Erased` row.
 */
#[AsAlias(DocumentErasures::class)]
final readonly class DbalDocumentErasures implements DocumentErasures
{
    /**
     * The rows the erasure may take: every state but the terminal one, spelled out so a state added later is not
     * erased by default.
     */
    private const array NON_TERMINAL = [
        DocumentState::Received,
        DocumentState::Scanning,
        DocumentState::Ready,
        DocumentState::Quarantined,
        DocumentState::ScanFailed,
    ];

    public function __construct(
        private Connection $connection,
    ) {
    }

    #[Override]
    public function boundTransactionTime(): void
    {
        RequiresActiveTransaction::ensure($this->connection, 'Bounding the erasure transaction');

        // `set_config(..., true)` is `SET LOCAL`: it ends with the transaction and leaks into no other one.
        $this->connection->fetchOne(
            "SELECT set_config('lock_timeout', :lock, true), set_config('statement_timeout', :statement, true)",
            [
                'lock' => ErasureLimits::LOCK_TIMEOUT_MILLISECONDS . 'ms',
                'statement' => ErasureLimits::STATEMENT_TIMEOUT_MILLISECONDS . 'ms',
            ],
        );
    }

    #[Override]
    public function markErased(string $documentId): bool
    {
        Uuid::ensure($documentId);

        // One statement: the transition and the sealed name's removal are the same fact. It names no scan, lease or
        // slot column, so what a worker holds in them is neither read nor written.
        return 1 === $this->connection->executeStatement(
            'UPDATE document SET state = :erased, filename_ciphertext = NULL '
            . 'WHERE id = CAST(:id AS UUID) AND state IN (:states)',
            [
                'erased' => DocumentState::Erased->value,
                'id' => \strtolower($documentId),
                'states' => \array_map(static fn (DocumentState $state): string => $state->value, self::NON_TERMINAL),
            ],
            ['states' => ArrayParameterType::STRING],
        );
    }

    #[Override]
    public function awaitingObjectDeletion(int $limit, ?string $afterId = null): array
    {
        $after = null === $afterId ? '' : ' AND id > CAST(:after AS UUID)';
        $parameters = ['erased' => DocumentState::Erased->value, 'limit' => $limit];

        if (null !== $afterId) {
            $parameters['after'] = \strtolower($afterId);
        }

        /** @var list<array{id: string, scope_id: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, scope_id FROM document WHERE state = :erased AND object_deleted_at IS NULL'
            . $after . ' ORDER BY id LIMIT :limit',
            $parameters,
            ['limit' => Types::INTEGER],
        );

        return \array_map(
            static fn (array $row): ErasedObject => new ErasedObject($row['id'], $row['scope_id']),
            $rows,
        );
    }

    #[Override]
    public function markObjectDeleted(string $documentId): bool
    {
        Uuid::ensure($documentId);

        return 1 === $this->connection->executeStatement(
            'UPDATE document SET object_deleted_at = NOW() '
            . 'WHERE id = CAST(:id AS UUID) AND state = :erased AND object_deleted_at IS NULL',
            ['id' => \strtolower($documentId), 'erased' => DocumentState::Erased->value],
        );
    }

    #[Override]
    public function reopenObjectDeletion(string $documentId): bool
    {
        Uuid::ensure($documentId);

        return 1 === $this->connection->executeStatement(
            'UPDATE document SET object_deleted_at = NULL, derivatives_released_at = NULL, '
            . 'derivatives_requested_at = NULL, erasure_reopened_at = NOW() '
            . 'WHERE id = CAST(:id AS UUID) AND state = :erased '
            . 'AND (object_deleted_at IS NOT NULL OR derivatives_released_at IS NOT NULL '
            . 'OR derivatives_requested_at IS NOT NULL)',
            ['id' => \strtolower($documentId), 'erased' => DocumentState::Erased->value],
        );
    }

    #[Override]
    public function objectsOverdue(int $olderThanSeconds, int $limit): array
    {
        /** @phpstan-var list<string> */
        return $this->connection->fetchFirstColumn(
            'SELECT d.id FROM document d WHERE d.state = :erased AND d.object_deleted_at IS NULL AND EXISTS ('
            . 'SELECT 1 FROM document_custody c WHERE c.document_id = d.id AND c.event = :event '
            . 'AND GREATEST(c.created_at, d.erasure_reopened_at) < NOW() - make_interval(secs => :age)) '
            . 'ORDER BY d.id LIMIT :limit',
            [
                'erased' => DocumentState::Erased->value,
                'event' => CustodyEvent::Erased->value,
                'age' => $olderThanSeconds,
                'limit' => $limit,
            ],
            ['age' => Types::INTEGER, 'limit' => Types::INTEGER],
        );
    }
}
