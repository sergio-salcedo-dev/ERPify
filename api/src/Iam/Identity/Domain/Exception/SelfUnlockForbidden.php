<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\Conflict;
use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;

/**
 * Raised when the acting administrator targets their own identity for an administrative account unlock.
 * `users.unlock` exists to recover an identity nothing else can reach — never to become a second,
 * credential-independent way into one's own account. Allowing it against oneself would make it exactly that,
 * regardless of whether the account happens to be locked at the moment it is invoked: a session belongs to
 * whoever is already authenticated, so "am I locked?" is never the question this guard answers.
 *
 * A {@see Conflict} (409), mirroring {@see SelfErasureForbidden}: the request is well-formed and authorized
 * but collides with that invariant, reusing the existing marker — no new error-contract entry. Off-request
 * callers (the CLI's `system` actor, which carries no id) can never trip it.
 */
final class SelfUnlockForbidden extends DomainException implements Conflict
{
    public static function forActor(string $userId): self
    {
        return new self(
            type: 'self-unlock-forbidden',
            title: 'An administrator cannot unlock their own account.',
            context: ['userId' => $userId],
        );
    }
}
