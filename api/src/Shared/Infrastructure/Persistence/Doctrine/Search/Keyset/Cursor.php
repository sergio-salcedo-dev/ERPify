<?php

declare(strict_types=1);

namespace Erpify\Shared\Infrastructure\Persistence\Doctrine\Search\Keyset;

/**
 * Infrastructure value object for a decoded cursor payload — the record that
 * {@see CursorCodec} signs into, and verifies out of, the wire token.
 *
 * Property names are descriptive; the WIRE payload keys are the pinned short
 * forms `{v, dir, values, fp}` (K3/FR15) and the mapping property ⇄ wire-key
 * lives solely in {@see CursorCodec::encode}/`decode`. Renaming a property here
 * never changes the wire format; changing a wire key would.
 *
 * - `version`: the serialization-contract version (1 initially), wire key `v`.
 *   A change to ANY of the serialization/canonicalization rules bumps it (FR15).
 * - `direction`: the navigation direction baked in at emission (`after`/
 *   `before`), wire key `dir`. It is an INTEGRITY BINDING ONLY (K2/AR21): the
 *   codec compares it against the expected direction, but it is NEVER read to
 *   decide navigation — the wire `after`/`before` param is the single routing
 *   authority.
 * - `values`: the boundary row's ordering-key values, at column precision.
 * - `fingerprint`: the fingerprint of the {@see QueryExecutionTrace} the cursor
 *   was issued for, wire key `fp`. The codec compares it against an expected
 *   fingerprint supplied by the caller; it never knows the trace itself.
 */
final readonly class Cursor
{
    public const string DIRECTION_AFTER = 'after';

    public const string DIRECTION_BEFORE = 'before';

    /**
     * @param array<string, mixed> $values
     */
    public function __construct(
        public int $version,
        public string $direction,
        public array $values,
        public string $fingerprint,
    ) {
    }
}
