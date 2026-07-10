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
}
