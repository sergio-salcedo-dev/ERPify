<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\Conflict;
use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;

/**
 * Raised when the acting administrator targets their own identity with a lifecycle transition (suspend or
 * deactivate). A committed transition revokes every session of its target after the commit, so a stolen
 * administrator session admitted before a recovery-secret redemption evicts it could otherwise suspend its own
 * identity and sign out the session that redemption has just established — the owner walled, the secret spent.
 * No legitimate self-targeted transition exists to protect: an identity that suspends or retires itself can
 * never reinstate itself, so the act belongs to another administrator. The CLI's `system` actor, which carries
 * no id, can never trip it.
 *
 * A {@see Conflict} (409), mirroring {@see SelfUnlockForbidden}: well-formed and authorized, but colliding with
 * that invariant.
 */
final class SelfStatusChangeForbidden extends DomainException implements Conflict
{
    public static function forActor(string $userId): self
    {
        return new self(
            type: 'self-status-change-forbidden',
            title: 'An administrator cannot change the status of their own account.',
            context: ['userId' => $userId],
        );
    }
}
