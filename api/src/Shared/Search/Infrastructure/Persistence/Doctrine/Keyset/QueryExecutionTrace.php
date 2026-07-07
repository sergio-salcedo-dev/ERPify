<?php

declare(strict_types=1);

namespace Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\Keyset;

/**
 * The single point of semantic truth for a keyset query's identity.
 *
 * Composes the immutable receipts of everything that shapes the result set
 * ordering and selection — entity, filters, sort, limit — plus the tenant slot,
 * into one sealed value object: constructed once, never mutated. Its canonical
 * representation (built by {@see FingerprintCanonicalizer}) is hashed into the
 * cursor's fingerprint, so a drift in how this trace is BUILT is the only
 * remaining mode of silent cursor corruption — which is exactly why
 * `TraceEquivalenceStabilityTest` pins its byte-for-byte stability.
 *
 * AR24 (trace governance): the trace must capture the full order-decision
 * space. Adding a future dimension (a real tenant, soft-delete scoping) is a
 * deliberate, central change here — a new constructor argument and a new
 * canonical segment — never an incidental patch scattered across collaborators.
 */
final readonly class QueryExecutionTrace
{
    /**
     * The tenant identity slot of the canonical chain, pinned in ONE place.
     *
     * why: Phase H of the SaaS multi-tenant roadmap will replace this constant
     * with a real per-tenant value promoted to a constructor argument. Today the
     * platform is single-tenant, so the slot is a fixed placeholder that still
     * reserves its canonical position. Changing this value (now or when the real
     * tenant lands, after PR3) alters every fingerprint and therefore REQUIRES a
     * bump of the cursor contract version `v` — it is never a silent change.
     */
    public const string TENANT_SLOT = '__erpify_single_tenant__';

    public function __construct(
        public string $entity,
        public AppliedFilters $filters,
        public AppliedSort $sort,
        public AppliedLimit $limit,
        /**
         * The repository-authored base query's structural identity (its `SELECT`/
         * `FROM`/`JOIN`/base `WHERE`), captured before any request filter is applied.
         *
         * why: the root entity FQCN alone does not distinguish two routes that share
         * an aggregate root but scope it differently — the account collection
         * (`FROM BankAccount ba JOIN Bank b`) and a bank's nested accounts
         * (`FROM BankAccount ba WHERE ba.bankId = :bankId`) mint the same order
         * decision. Without this segment their fingerprints collide, so a cursor
         * minted on one is accepted on the other and paginates across a foreign
         * scope — a privilege-scope-widening bypass once the two routes gain
         * divergent access. Binding the base query into the fingerprint makes a
         * cross-scope cursor fail fingerprint validation (`422 invalid-cursor`).
         * Structural, not value-bound: `:bankId = A` and `:bankId = B` share the
         * base identity — the parameter value is the filter's job, not the scope's.
         */
        public string $baseQuery,
    ) {
    }

    public function tenant(): string
    {
        return self::TENANT_SLOT;
    }
}
