<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Iam\Identity\Fixtures;

use Erpify\Shared\Audit\Application\ActorAnonymisationResult;
use Erpify\Shared\Audit\Application\AuditActorAnonymiser;
use Override;
use RuntimeException;

/**
 * An {@see AuditActorAnonymiser} whose {@see anonymise()} always throws — the trail store going down mid-chain,
 * after the identity is hard-deleted but before the sessions are purged. Overriding the real anonymiser (which
 * is outside the request's authentication path) lets a functional test drive that failure over real HTTP and
 * prove the whole erasure rolls back on the database: no half-erased identity, no half-anonymised trail.
 *
 * @internal
 */
final readonly class FailingAuditActorAnonymiser implements AuditActorAnonymiser
{
    #[Override]
    public function countFor(string $actorId): int
    {
        throw new RuntimeException('audit trail store unavailable');
    }

    #[Override]
    public function anonymise(string $actorId): ActorAnonymisationResult
    {
        throw new RuntimeException('audit trail store unavailable');
    }
}
