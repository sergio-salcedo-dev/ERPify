<?php

declare(strict_types=1);

namespace Erpify\Iam\Session\Infrastructure\Security;

use Erpify\Iam\Session\Domain\Entity\Session;
use Erpify\Iam\Session\Domain\SessionId;

/**
 * The session a request was admitted with, paired with the {@see SessionId} that correlates it.
 *
 * Two members and not one, because the id is NOT derivable from the entity — or rather, it is derivable and
 * must not be. `Session` carries its own primary key, but reading it back would mean
 * `SessionId::fromString($session->getId() ?? …)`, and `fromString` validates through `Uuid::ensure`, which
 * throws `InvalidUuidException` — a 400 `invalid-uuid` on a path whose only outcomes are 401 and 503. The
 * correlation answers `null` instead of throwing on a value it cannot read, and that difference is the whole
 * reason this pair exists rather than a bare `Session`.
 *
 * It lives here and not in `Application/Resource/`: it holds an entity, and the resource DTOs of that
 * directory are flat and scalar-only by contract. "Admitted" is a property of the HTTP request, not a
 * vocabulary the use cases speak.
 */
final readonly class AdmittedSession
{
    public function __construct(
        public SessionId $id,
        public Session $session,
    ) {
    }

    /**
     * The subject this request acts for. Delegated rather than reached through, so a caller states what it
     * needs instead of navigating the pair to get it.
     */
    public function userId(): string
    {
        return $this->session->userId();
    }
}
