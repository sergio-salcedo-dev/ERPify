<?php

declare(strict_types=1);

namespace Erpify\Iam\Invitation\Domain\Enum;

/**
 * Lifecycle state of an {@see \Erpify\Iam\Invitation\Domain\Entity\Invitation}: the delivery machine of a
 * pending onboarding. `CREATED → SENT` is the mint-then-deliver step; a `SENT` invitation then reaches exactly
 * one terminal state — `ACCEPTED` (its owner set a password and became active), `REVOKED` (an administrator
 * pulled it) or `EXPIRED` (its TTL lapsed).
 *
 * Pure vocabulary, mirroring {@see \Erpify\Iam\Session\Domain\Enum\SessionStatus} and
 * {@see \Erpify\Iam\Identity\Domain\Enum\IdentityStatus}: no method here ranks a state or encodes a transition
 * — the legal-transition machine lives on the aggregate.
 */
enum InvitationStatus: string
{
    case CREATED = 'CREATED';
    case SENT = 'SENT';
    case ACCEPTED = 'ACCEPTED';
    case REVOKED = 'REVOKED';
    case EXPIRED = 'EXPIRED';
}
