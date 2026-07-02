<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Identity\Domain\Enum;

/**
 * Authorization roles a {@see \Erpify\Backoffice\Identity\Domain\Entity\User} carries as data.
 *
 * The `->value`s are the domain vocabulary and never carry Symfony's `ROLE_` prefix: the mapping is
 * one-directional Domain -> Infrastructure -> Symfony, so a Security adapter prepends `ROLE_` when it
 * emits `getRoles()`; the domain is the source of truth and never learns the framework prefix.
 */
enum Role: string
{
    case AUDIT_READER = 'AUDIT_READER';
}
