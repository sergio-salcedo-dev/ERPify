<?php

declare(strict_types=1);

namespace Erpify\Iam\Session\Domain\Enum;

/**
 * Per-actor lifecycle state of a {@see \Erpify\Iam\Session\Domain\Entity\Session}: the axis the admission gate
 * reads to decide whether a request may continue. Only two states exist because the lifecycle has a single,
 * terminal transition — `ACTIVE → REVOKED`.
 *
 * There is deliberately no `EXPIRED`: temporal validity is a separate axis, the predicate `expiresAt <= now`
 * that the gate query and the "my sessions" projection apply directly, never a persisted state. Materialising
 * expiry as a state would need a WRITER of that transition and would conflate the per-actor lifecycle with the
 * time axis; `EXPIRED` earns its place only when such a writer genuinely exists.
 *
 * The retention sweep (`iam:session:prune`) is not that writer, and the distinction is the whole point: it
 * DELETES rows whose retention window has elapsed and reads `expires_at` as the same predicate everything
 * else does. A row it removes has no state to be in.
 *
 * The enum is pure vocabulary, mirroring {@see \Erpify\Iam\Identity\Domain\Enum\IdentityStatus}: no method here
 * ranks a state or encodes a transition. The legal-transition machine lives on the aggregate.
 */
enum SessionStatus: string
{
    case ACTIVE = 'ACTIVE';
    case REVOKED = 'REVOKED';
}
