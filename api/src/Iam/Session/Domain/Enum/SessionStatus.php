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
 * expiry as a state would need a sweeper to write the transition and would conflate the per-actor lifecycle
 * with the time axis; the day a sweeper genuinely exists, `EXPIRED` can be reintroduced.
 *
 * The enum is pure vocabulary, mirroring {@see \Erpify\Iam\Identity\Domain\Enum\IdentityStatus}: no method here
 * ranks a state or encodes a transition. The legal-transition machine lives on the aggregate.
 */
enum SessionStatus: string
{
    case ACTIVE = 'ACTIVE';
    case REVOKED = 'REVOKED';
}
