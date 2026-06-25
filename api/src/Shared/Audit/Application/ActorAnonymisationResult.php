<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Application;

/**
 * Outcome of a GDPR erasure pass over one subject's audit trail: the freshly minted pseudonym that
 * replaced the original actor id in every matched row, and how many rows were rewritten. The pseudonym
 * is surfaced (never the original id) so an operator — and the self-audit entry the erasure emits — can
 * reference the now-anonymous subject without re-identifying the person.
 */
final readonly class ActorAnonymisationResult
{
    public function __construct(
        public string $pseudonym,
        public int $affectedRows,
    ) {
    }
}
