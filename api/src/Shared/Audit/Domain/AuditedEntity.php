<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Domain;

/**
 * Opt-in marker for an aggregate whose writes the regulatory trail captures: the Doctrine `onFlush`
 * change-data-capture listener records a field-level diff for an entity only when it implements this
 * interface, so write auditing is an explicit per-aggregate decision, never "everything the ORM touches".
 *
 * The aggregate declares its own trail identity *and* its own action vocabulary rather than the listener
 * deriving either from the PHP class: the persisted `resource_type` and the semantic `action`
 * (`BANK_CREATED` / `BANK_UPDATED` / `BANK_DELETED`) are compliance contracts that must stay stable across
 * class refactors, and the owning module — not a central convention in `Shared` — is the authority on how it
 * names a change. A new audited aggregate adds its mapping in its own class without touching the shared
 * capture listener, mirroring how {@see AuditPolicy} holds no catalogue of concrete module routes.
 */
interface AuditedEntity
{
    public function auditResource(): AuditResource;

    public function auditAction(AuditWriteOperation $operation): string;
}
