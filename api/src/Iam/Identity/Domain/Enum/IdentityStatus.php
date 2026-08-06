<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Domain\Enum;

/**
 * Lifecycle state of a {@see \Erpify\Iam\Identity\Domain\Entity\User} identity — the axis the admission
 * check reads between proving credentials and minting a session, where only `ACTIVE` is admitted.
 *
 * `INVITED` is an identity provisioned by invitation whose credential is not yet set (its `password_hash`
 * stays null until it activates); `SUSPENDED` and `DEACTIVATED` are post-active walls — a suspended
 * identity can be reinstated, a deactivated one is retired. There is deliberately no `PENDING`: invitation,
 * not a pending self-registration, is how an identity comes into being.
 *
 * `REVOKED` is the terminal state of an identity that never came into service: its invitation was withdrawn
 * before it was accepted. It is deliberately NOT `DEACTIVATED` — the two answer different questions about a
 * person, and collapsing them would tell an incident responder that a withdrawn invitation is a retired
 * member. It is equally deliberately a state and not a deletion: the invitation already put this id in the
 * reproducible log and in the membership that admitted it, so removing the row would strand references whose
 * erasure has a declared owner, and that owner refuses any subject still carrying `ADMIN` — which is exactly
 * the invitation a revocation is most likely to be pulling.
 *
 * The enum is pure vocabulary, mirroring {@see Role}: no method here ranks a state or encodes a transition.
 * The legal-transition machine lives on the {@see \Erpify\Iam\Identity\Domain\Entity\User} aggregate and the
 * admission policy in the Security user checker, so neither Domain vocabulary nor Application branches on a
 * raw case.
 */
enum IdentityStatus: string
{
    case INVITED = 'INVITED';
    case ACTIVE = 'ACTIVE';
    case SUSPENDED = 'SUSPENDED';
    case DEACTIVATED = 'DEACTIVATED';
    case REVOKED = 'REVOKED';
}
