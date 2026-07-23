<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\Conflict;
use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;

/**
 * Raised when erasure targets an identity that still carries `ADMIN`. Erasure is irreversible and
 * pseudonymises the subject's entire attribution in the compliance trail, so an administrator must be
 * demoted first: the demotion is itself an audited `SECURITY` record, which turns "one administrator erased
 * a peer" from a single unexplained act into a declared sequence in the record it is meant to protect.
 *
 * The refusal is not a privilege the subject holds over their own erasure — demote-then-erase is always
 * available, so the right to erasure stays satisfiable — and it deliberately ignores the subject's status: a
 * suspended administrator still carries the role, and the concern is the role, not the activity.
 *
 * A {@see Conflict} (409), like {@see LastActiveAdministratorProtected}: the request is well-formed and
 * authorized but collides with a state invariant. It subsumes that invariant on the erasure path — the last
 * active administrator necessarily carries `ADMIN` — which is why erasure no longer reasons about the
 * administrator set at all; keeping at least one administrator alive is enforced where the role changes.
 */
final class AdministratorErasureRequiresDemotion extends DomainException implements Conflict
{
    public static function forUser(string $userId): self
    {
        return new self(
            type: 'administrator-erasure-requires-demotion',
            title: 'Cannot erase an identity that still holds the administrator role; remove the role first.',
            context: ['userId' => $userId],
        );
    }
}
