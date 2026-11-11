<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Persistence\Doctrine;

use Doctrine\DBAL\Connection;
use Erpify\Documents\Document\Domain\DocumentState;
use Erpify\Shared\Uuid\Domain\Uuid;

/**
 * The promotion's compare-and-set on `document.promoted_at`: one statement, set to the database clock only on a
 * `Received` row that is not promoted yet, so the writer and the roll-forward cannot both claim it. Kept apart from
 * the repository whose single `UPDATE document` is the erasure's rewrite, which a lock-order gate reads alone.
 */
final readonly class DbalDocumentPromotions
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function markPromoted(string $id): bool
    {
        Uuid::ensure($id);

        return 1 === (int) $this->connection->executeStatement(
            'UPDATE document SET promoted_at = NOW() '
            . 'WHERE id = CAST(:id AS UUID) AND state = :received AND promoted_at IS NULL',
            ['id' => \strtolower($id), 'received' => DocumentState::Received->value],
        );
    }
}
