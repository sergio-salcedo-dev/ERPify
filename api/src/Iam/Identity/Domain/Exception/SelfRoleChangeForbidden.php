<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\Conflict;
use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;

/**
 * Raised when the acting administrator targets their own identity with a role change. A committed role change
 * revokes every session of its target after the commit, so a change aimed at oneself is a way to sign one's own
 * identity out everywhere — and a stolen administrator session admitted before a recovery-secret redemption
 * evicts it would reach the session that redemption has just established, leaving the owner with the secret
 * spent and no session. Refusing the self-target outright, whatever the requested set, closes that without
 * re-checking the caller under a lock: nothing a self-targeted request could legitimately want (widening one's
 * own set, re-sending it) is worth a teardown of every device the owner holds. Another administrator changes
 * the roles; the CLI's `system` actor, which carries no id, can never trip it.
 *
 * A {@see Conflict} (409), mirroring {@see SelfUnlockForbidden}: well-formed and authorized, but colliding with
 * that invariant.
 */
final class SelfRoleChangeForbidden extends DomainException implements Conflict
{
    public static function forActor(string $userId): self
    {
        return new self(
            type: 'self-role-change-forbidden',
            title: 'An administrator cannot change their own roles.',
            context: ['userId' => $userId],
        );
    }
}
