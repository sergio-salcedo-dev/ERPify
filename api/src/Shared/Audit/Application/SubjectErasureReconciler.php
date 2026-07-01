<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Application;

/**
 * Reconciles crypto-shredding against its evidence: every destroyed data-encryption key must have a
 * matching `GDPR_SUBJECT_ERASED` audit entry (the scope travels in that entry's `metadata`, which is what
 * this reads — not the `audit_log.encryption_scope_id` column). The transactional `erase-subject` path
 * commits the key destruction and the security entry together, so it never diverges on its own; this guards
 * the paths that can — an out-of-band or manual key destruction, a partial repair, or a future non-atomic
 * caller. A destroyed key without evidence is an integrity divergence surfaced rather than left silent
 * (ADR D15 / D4.1 cross-check).
 */
interface SubjectErasureReconciler
{
    /**
     * @return list<string> the encryption scope ids of destroyed keys with no erasure evidence (empty when
     *                      everything reconciles)
     */
    public function unreconciledScopes(): array;
}
