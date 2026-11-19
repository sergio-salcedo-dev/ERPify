<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Persistence\Doctrine;

use Doctrine\DBAL\Connection;
use Erpify\Documents\Document\Application\Derivative\DocumentDerivativeOrigin;
use Erpify\Documents\Document\Domain\DocumentState;
use Erpify\Shared\Images\Application\OriginGuard;
use Erpify\Shared\Images\Domain\DerivativeOrigin;
use LogicException;
use Override;

/**
 * Admits a derivative only for a `Ready` document, under `FOR SHARE` on its row. That lock conflicts with the erasure's
 * `FOR UPDATE` (`DoctrineDocumentRepository::lockForUpdate()`), so the image insert that follows commits either
 * before the erasure takes the row, and the release sweep then finds the image, or after the erasure committed, and
 * this answers false. It cannot cycle with the erasure, which never touches `image`. Scans compare-and-set the row
 * without reading it first, so they wait on this lock for one insert at most.
 */
final readonly class DbalDocumentOriginGuard implements OriginGuard
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    #[Override]
    public function admits(DerivativeOrigin $origin): bool
    {
        RequiresActiveTransaction::ensure($this->connection, 'Admitting a document derivative');

        // Inside a caller's own unit of work the lock would last until THAT commits, holding the erasure behind
        // whatever else it does, and a refusal would close the manager that unit still needs.
        if ($this->connection->getTransactionNestingLevel() > 1) {
            throw new LogicException('Admitting a document derivative must own its transaction, not join one.');
        }

        if (DocumentDerivativeOrigin::TYPE !== $origin->type) {
            return false;
        }

        $state = $this->connection->fetchOne(
            'SELECT state FROM document WHERE id = CAST(:id AS UUID) FOR SHARE',
            ['id' => $origin->id],
        );

        return DocumentState::Ready->value === $state;
    }
}
