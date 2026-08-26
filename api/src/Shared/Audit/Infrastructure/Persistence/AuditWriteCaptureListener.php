<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Infrastructure\Persistence;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
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
        private PiiDiffSealer $piiDiffSealer,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $entityManager = $args->getObjectManager();

        if (!$entityManager->getConnection()->isTransactionActive()) {
            return;
        }

        foreach (AuditWriteOperation::cases() as $operation) {
            foreach ($this->capture($entityManager, $operation) as $entry) {
                $this->writer->write($entry);
            }
        }
    }

    /**
     * @return list<AuditLogEntry>
     */
    private function capture(EntityManagerInterface $entityManager, AuditWriteOperation $operation): array
    {
        $unitOfWork = $entityManager->getUnitOfWork();

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

            // Mint the row id up front so the one id is both bound into each sealed value's AAD and carried
            // as the persisted primary key: sealing runs before the entry is built, so the id cannot come
            // from it, and the AAD's id must equal the row it lands on or a reader could never reconstruct it.
            $auditLogId = $this->entryFactory->mintId();

            $diff = $this->changeDiff->of($this->changesOf($entityManager, $unitOfWork, $entity, $operation));
            $diff['operation'] = $operation->name;

            $sealed = $this->piiDiffSealer->seal($entity, $diff, $auditLogId);

            $entries[] = $this->entryFactory->create(
                $entity->auditAction($operation),
                AuditLevel::CHANGE,
                $entity->auditResource(),
                $sealed->metadata,
                $sealed->encryptionScopeId,
                $auditLogId,
            );
        }

        return $entries;
    }

    /**
     * The field-level changeset a `change` row records. An insert or update reuses the changeset Doctrine
     * has already computed; a delete has none — Doctrine computes no changeset for a removed entity — so its
     * final state is read off the class metadata as a `[value, null]` snapshot, preserving the diff's
     * "before" that would otherwise be gone once the row is removed.
     *
     * @return array<string, mixed>
     */
    private function changesOf(
        EntityManagerInterface $entityManager,
        UnitOfWork $unitOfWork,
        object $entity,
        AuditWriteOperation $operation,
    ): array {
        if (AuditWriteOperation::DELETED !== $operation) {
            return $unitOfWork->getEntityChangeSet($entity);
        }

        return $this->finalStateSnapshot($entityManager, $entity);
    }

    /**
     * The complete final state of an aggregate about to be removed, as a `[value, null]` snapshot. Mapped
     * scalar fields carry their value; a to-one association carries the referenced id alone, never the
     * related object, so the trail records what the aggregate pointed at without pulling another module's
     * graph into it. To-many sides are out of field-level scalar scope, like {@see AuditChangeDiff} skips a
     * collection change.
     *
     * @return array<string, array{mixed, null}>
     */
    private function finalStateSnapshot(EntityManagerInterface $entityManager, object $entity): array
    {
        $metadata = $entityManager->getClassMetadata($entity::class);
        $snapshot = [];

        foreach ($metadata->getFieldNames() as $field) {
            $snapshot[$field] = [$metadata->getFieldValue($entity, $field), null];
        }

        foreach ($metadata->getAssociationNames() as $association) {
            if (!$metadata->isSingleValuedAssociation($association)) {
                continue;
            }

            $snapshot[$association] = [
                $this->referencedId($entityManager, $metadata->getFieldValue($entity, $association)),
                null,
            ];
        }

        return $snapshot;
    }

    private function referencedId(EntityManagerInterface $entityManager, mixed $related): ?string
    {
        if (!\is_object($related)) {
            return null;
        }

        $identifiers = $entityManager->getClassMetadata($related::class)->getIdentifierValues($related);

        if (1 !== \count($identifiers)) {
            return null;
        }

        $id = \reset($identifiers);

        return \is_scalar($id) ? (string) $id : null;
    }
}
