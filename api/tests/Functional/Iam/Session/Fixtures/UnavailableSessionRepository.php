<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Iam\Session\Fixtures;

use Erpify\Iam\Session\Domain\Entity\Session;
use Erpify\Iam\Session\Domain\Exception\SessionStoreUnavailable;
use Erpify\Iam\Session\Domain\Repository\SessionRepository;
use Erpify\Iam\Session\Domain\SessionId;
use Override;

/**
 * A {@see SessionRepository} standing in for a store whose reads are down: every read and bulk revocation raises
 * {@see SessionStoreUnavailable}, so a functional test can drive the gate's fail-closed path end-to-end (a real
 * HTTP request through the whole error-contract pipeline) without killing the database connection.
 *
 * {@see save()} is a no-op on purpose: the test seats its authenticated correlation through this double before
 * the request, and the gate only ever reads — so the read outage is what the scenario exercises, not the write.
 *
 * @internal
 */
final readonly class UnavailableSessionRepository implements SessionRepository
{
    #[Override]
    public function save(Session $session): void
    {
        // Deliberate no-op — see the class docblock: seating the test correlation must pass through this double.
    }

    #[Override]
    public function findActiveById(SessionId $id): ?Session
    {
        throw SessionStoreUnavailable::storeUnreachable();
    }

    #[Override]
    public function findByUserId(string $userId): array
    {
        throw SessionStoreUnavailable::storeUnreachable();
    }

    #[Override]
    public function revokeOthersForUser(string $userId, SessionId $currentSessionId): void
    {
        throw SessionStoreUnavailable::storeUnreachable();
    }

    #[Override]
    public function revokeAllForUser(string $userId): void
    {
        throw SessionStoreUnavailable::storeUnreachable();
    }
}
