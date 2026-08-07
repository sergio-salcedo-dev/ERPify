<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Erpify\Shared\Audit\Application\AuditResourceAnonymiser;
use Erpify\Shared\Audit\Domain\AuditResource;
use Erpify\Shared\Uuid\Domain\Uuid;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * {@link AuditResourceAnonymiser} over `audit_log` via plain DBAL: one parameterised UPDATE that overwrites
 * a person-denoting resource reference with the caller's pseudonym and raises `resource_erased` — never a
 * DELETE, so the trail survives while it stops naming the person. The narrowing
 * `WHERE resource_type = … AND resource_id = …` makes it idempotent and lets it ride the existing
 * `audit_log_resource_idx` (leading column present), so it stays a single indexed write however large the
 * table grows.
 *
 * **What it deliberately does NOT touch, and why.** It leaves `actor_id`, `ip`, `user_agent` and
 * `actor_erased` alone. Those four describe the party that *acted*, which is not the person this statement
 * erases: redacting such a row's `ip` would destroy a third party's evidence, and raising `actor_erased` on
 * it would report that party as an erased actor, corrupting the very flag `docs/adr/audit-activity-log.md`
 * D4.1 materialised to make erasure queryable. The two axes are separate columns because they are separate
 * people; this writes only its own.
 *
 * That is a rule about what the columns MEAN, not a claim about who wrote the rows — and it has to be, because
 * several files now name a person as an audit resource: the erasure that runs this, plus the role change and
 * the invitation that record an administrator acting on a user. The third-party case is live, not
 * hypothetical, and this statement is indifferent to it: the `WHERE` matches on `(resource_type, resource_id)`
 * and never on who wrote the row, so a sibling writer's rows are erased exactly like the erasure's own.
 * `PersonResourceErasureGateTest` bounds WHERE those writers may live — the module declared to erase the type
 * — rather than how many there are.
 *
 * The pseudonym is guarded with {@see Uuid::ensure()} at this edge like the sibling anonymiser: the only
 * caller already holds one minted by {@see DbalAuditActorAnonymiser}, so this is defence in depth — a future
 * caller passing a malformed value fails with a domain error here rather than a raw driver exception from
 * the `CAST(… AS UUID)`.
 */
#[AsAlias(AuditResourceAnonymiser::class)]
final readonly class DbalAuditResourceAnonymiser implements AuditResourceAnonymiser
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    #[Override]
    public function anonymise(AuditResource $resource, string $pseudonym): int
    {
        Uuid::ensure($pseudonym);

        return (int) $this->connection->executeStatement(
            'UPDATE audit_log '
            . 'SET resource_id = CAST(:pseudonym AS UUID), resource_erased = TRUE '
            . 'WHERE resource_type = :resource_type AND resource_id = CAST(:resource_id AS UUID)',
            [
                'pseudonym' => $pseudonym,
                'resource_type' => $resource->type,
                'resource_id' => $resource->id,
            ],
        );
    }
}
