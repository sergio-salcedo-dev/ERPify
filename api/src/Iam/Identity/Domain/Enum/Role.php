<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Domain\Enum;

/**
 * Authorization roles a {@see \Erpify\Iam\Identity\Domain\Entity\User} carries as data.
 *
 * The `->value`s are the domain vocabulary and never carry Symfony's `ROLE_` prefix: the mapping is
 * one-directional Domain -> Infrastructure -> Symfony, so a Security adapter prepends `ROLE_` when it
 * emits `getRoles()`; the domain is the source of truth and never learns the framework prefix.
 *
 * `VIEWER < EDITOR < MANAGER < ADMIN` is the privilege ladder the resource policy reads as tiers (each
 * tier a widening verb set); `AUDIT_READER` is orthogonal — a capability grant, not a rung on the ladder.
 * The enum is pure vocabulary: no method here ranks or maps a role to permissions — that lives entirely in
 * the Infrastructure authorization policy, so neither Domain nor Application ever branches on a role.
 */
enum Role: string
{
    case VIEWER = 'VIEWER';
    case EDITOR = 'EDITOR';
    case MANAGER = 'MANAGER';
    case ADMIN = 'ADMIN';
    case AUDIT_READER = 'AUDIT_READER';
}
