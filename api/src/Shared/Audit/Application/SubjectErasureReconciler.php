<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Application;

/**
 * Reconciles crypto-shredding against its evidence: every destroyed data-encryption key must have a
 * matching `GDPR_SUBJECT_ERASED` audit entry. A destroyed key without that evidence is an integrity
 * divergence — the erasure happened but its self-audit was lost (the known non-atomic window between
 * destroying the key and recording the security entry). Surfacing it makes the gap detectable rather than
 * silent (ADR D15 / D4.1 cross-check).
 */
interface SubjectErasureReconciler
{
    /**
     * @return list<string> the encryption scope ids of destroyed keys with no erasure evidence (empty when
     *                      everything reconciles)
     */
    public function unreconciledScopes(): array;
}
