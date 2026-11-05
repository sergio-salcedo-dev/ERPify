<?php

declare(strict_types=1);

namespace Erpify\Shared\Privacy\Application;

/**
 * The seam through which an identity erasure reaches a context that is neither Iam, Organization nor Shared,
 * without importing that context. Each person's context orchestrates the erasure; a context that persists
 * their id owns the removal of its own copy and exposes it here, so the orchestrator learns that "a context
 * erases references to a person" and nothing about what a document or a custody row is.
 *
 * Both consumers of the identity purge — the erasure itself and its re-sweep — call it inside their one
 * transaction, which is why an implementation joins that transaction instead of opening its own and why a
 * failure inside it aborts the whole erasure: nothing is half-erased, and a re-run is safe.
 *
 * **The implementation owns its lock order, and the caller owns where in the chain it runs.** The lock order
 * across contexts is fixed by the caller (identity, sessions, audit and event-store rows are already held when
 * this runs), so an implementation may take row locks of its own tables and no others.
 *
 * It rewrites or removes; it never judges whether the subject is a live person, which is knowledge of the
 * context that owns people. Idempotent: a second pass over the same subject finds nothing naming them.
 *
 * A single implementer exists today (`Documents`). The next one turns this into a collection by tag, in the
 * way {@see PersonReferenceSource} already is, rather than by editing both consumers.
 */
interface PersonReferenceErasure
{
    /**
     * @return int how many persisted references to the subject were rewritten or removed
     */
    public function eraseReferencesTo(string $subjectId): int;
}
