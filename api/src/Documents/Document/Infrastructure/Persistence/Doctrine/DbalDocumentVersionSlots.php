<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Persistence\Doctrine;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Erpify\Documents\Document\Domain\DocumentState;
use Erpify\Documents\Document\Domain\Versioning\DocumentVersionSlots;
use Erpify\Shared\Uuid\Domain\Uuid;
use LogicException;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * The series mutex and the slot read over `document_series` and `document`, in plain DBAL: the series row is a lock and
 * nothing else — no head pointer, no count, no column a transition must remember to move — so it is not an entity.
 *
 * The row is inserted with the first version of its series ({@see open()}, from the document repository's insert),
 * and `document.series_id` references it, so every series a take can name has a row to lock.
 *
 * The slot read is one recursive query down `predecessor_id`, served by its foreign key's index. `UNION` rather
 * than `UNION ALL` stops on a repeated row, so even a corrupted link could not make it loop; the links themselves
 * cannot close a cycle, since a predecessor exists before its successor and neither column is ever updated.
 */
#[AsAlias(DocumentVersionSlots::class)]
final readonly class DbalDocumentVersionSlots implements DocumentVersionSlots
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    #[Override]
    public function lockSeries(string $seriesId): void
    {
        Uuid::ensure($seriesId);
        RequiresActiveTransaction::ensure($this->connection, 'Locking a document series');

        $locked = $this->connection->fetchOne(
            'SELECT id FROM document_series WHERE id = CAST(:id AS UUID) FOR UPDATE',
            ['id' => \strtolower($seriesId)],
        );

        if (false === $locked) {
            throw new LogicException('A document names a series that has no row to lock.');
        }
    }

    #[Override]
    public function isSlotHeld(string $predecessorId): bool
    {
        Uuid::ensure($predecessorId);

        return false !== $this->connection->fetchOne(
            'WITH RECURSIVE below (id, state) AS ('
            . 'SELECT d.id, d.state FROM document d WHERE d.predecessor_id = CAST(:id AS UUID) '
            . 'UNION '
            . 'SELECT d.id, d.state FROM document d JOIN below b ON d.predecessor_id = b.id'
            . ') SELECT 1 FROM below WHERE state IN (:live) LIMIT 1',
            [
                'id' => \strtolower($predecessorId),
                'live' => \array_map(
                    static fn (DocumentState $state): string => $state->value,
                    DocumentState::slotHolders(),
                ),
            ],
            ['live' => ArrayParameterType::STRING],
        );
    }

    /**
     * Inserts the row of a new series, in the transaction that files its first version and before the document row
     * that references it. A first version locks nothing here: its series is invisible until that transaction commits.
     */
    public function open(string $seriesId): void
    {
        Uuid::ensure($seriesId);
        RequiresActiveTransaction::ensure($this->connection, 'Opening a document series');

        $this->connection->executeStatement(
            'INSERT INTO document_series (id, created_at) VALUES (CAST(:id AS UUID), NOW())',
            ['id' => \strtolower($seriesId)],
        );
    }
}
