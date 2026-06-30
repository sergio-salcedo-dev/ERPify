<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Domain;

/**
 * Opt-in marker for an aggregate whose writes the regulatory trail captures: the Doctrine `onFlush`
 * change-data-capture listener records a field-level diff for an entity only when it implements this
 * interface, so write auditing is an explicit per-aggregate decision, never "everything the ORM touches".
 *
 * The aggregate declares its own trail identity rather than the listener deriving it from the PHP class:
 * the persisted `resource_type` is a compliance contract that must stay stable across class refactors, and
 * the aggregate is the one place that authoritatively knows its id.
 */
interface AuditedEntity
{
    public function auditResource(): AuditResource;
}
