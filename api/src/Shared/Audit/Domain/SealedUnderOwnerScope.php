<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Domain;

/**
 * Opt-in for an audited entity whose personal-data fields belong to ANOTHER aggregate's subject: the change capture
 * seals them under that owner's encryption scope instead of the entity's own, so the owner's erasure — which destroys
 * the owner's key — shreds them too. Without it a dependent row (a link, a line) would seal under a scope no erasure
 * ever destroys, and its personal data would outlive the subject it belongs to.
 *
 * The audit row still names the entity itself ({@see AuditedEntity::auditResource()}); only the key changes.
 */
interface SealedUnderOwnerScope
{
    /** The audit resource whose encryption scope (`<type>:<id>`) the entity's personal-data fields are sealed under. */
    public function auditSealingOwner(): AuditResource;
}
