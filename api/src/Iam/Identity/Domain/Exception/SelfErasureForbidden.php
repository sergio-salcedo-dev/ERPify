<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\Conflict;
use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;

/**
 * Raised when the acting administrator targets their own identity for GDPR erasure. The two roles the
 * operation needs are incompatible in one person: the subject must cease to exist, yet the same person must
 * survive as the *actor* who attributes the erasure's own legal evidence — no write ordering reconciles
 * "anonymise every row of the subject" with "keep nominal evidence of who erased". So it is refused before
 * the transaction opens.
 *
 * A {@see Conflict} (409): the request is well-formed and authorized but collides with that invariant,
 * reusing the existing marker — no new error-contract entry. Off-request callers (the CLI's `system` actor,
 * which carries no id) can never trip it, so it coexists with the shared erasure use case.
 */
final class SelfErasureForbidden extends DomainException implements Conflict
{
    public static function forActor(string $userId): self
    {
        return new self(
            type: 'self-erasure-forbidden',
            title: 'An administrator cannot erase their own identity.',
            context: ['userId' => $userId],
        );
    }
}
