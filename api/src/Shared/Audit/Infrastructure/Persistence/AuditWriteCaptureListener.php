<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Infrastructure\Persistence;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\UnitOfWork;
use Erpify\Shared\Audit\Application\AuditChangeDiff;
use Erpify\Shared\Audit\Application\AuditEntryFactory;
use Erpify\Shared\Audit\Application\AuditLogEntry;
use Erpify\Shared\Audit\Application\AuditLogWriter;
use Erpify\Shared\Audit\Domain\AuditedEntity;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Domain\AuditWriteOperation;

/**
 * Regulatory write-side change-data-capture. On every flush this reads the UnitOfWork changeset for each
 * audited aggregate ({@see AuditedEntity}) and records one `change` row per write — a semantic action
 * (`BANK_CREATED` / `BANK_UPDATED` / `BANK_DELETED`) over the field-level diff. Capture is opt-in by marker,
 * never "everything the ORM touches", so a flush of unmarked entities emits nothing.
 *
 * The row is written on the same connection inside the flush, so it commits with the business write it
 * describes or, on failure, rolls back with it — no change without its row, no row without its change. That
 * atomicity is anchored on the ambient transaction the write runs in (the use-case `wrapInTransaction`, the
 * universal write path here). Doctrine dispatches `onFlush` *before* it opens the flush transaction, so a
 * flush with no transaction in flight is out-of-band persistence (fixtures, seeds, migrations) — not an
 * audited business action, and unbindable to a transaction anyway — and is left uncaptured.
 *
 * Raw-DBAL via {@see AuditLogWriter} — never `persist()` — keeps the capture out of the very UnitOfWork it
 * is reading.
 */
#[AsDoctrineListener(event: Events::onFlush)]
final readonly class AuditWriteCaptureListener
{
    public function __construct(
        private AuditEntryFactory $entryFactory,
        private AuditLogWriter $writer,
        private AuditChangeDiff $changeDiff,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $entityManager = $args->getObjectManager();

        if (!$entityManager->getConnection()->isTransactionActive()) {
            return;
        }

        $unitOfWork = $entityManager->getUnitOfWork();

        $entries = [
            ...$this->capture($unitOfWork, AuditWriteOperation::CREATED),
            ...$this->capture($unitOfWork, AuditWriteOperation::UPDATED),
            ...$this->capture($unitOfWork, AuditWriteOperation::DELETED),
        ];

        foreach ($entries as $entry) {
            $this->writer->write($entry);
        }
    }

    /**
     * @return list<AuditLogEntry>
     */
    private function capture(UnitOfWork $unitOfWork, AuditWriteOperation $operation): array
    {
        $entities = match ($operation) {
            AuditWriteOperation::CREATED => $unitOfWork->getScheduledEntityInsertions(),
            AuditWriteOperation::UPDATED => $unitOfWork->getScheduledEntityUpdates(),
            AuditWriteOperation::DELETED => $unitOfWork->getScheduledEntityDeletions(),
        };

        $entries = [];

        foreach ($entities as $entity) {
            if (!$entity instanceof AuditedEntity) {
                continue;
            }

            $entries[] = $this->entryFactory->create(
                $entity->auditAction($operation),
                AuditLevel::CHANGE,
                $entity->auditResource(),
                $this->changeDiff->of($unitOfWork->getEntityChangeSet($entity)),
            );
        }

        return $entries;
    }
}
