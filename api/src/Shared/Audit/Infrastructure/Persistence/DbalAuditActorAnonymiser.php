<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Erpify\Shared\Audit\Application\ActorAnonymisationResult;
use Erpify\Shared\Audit\Application\AuditActorAnonymiser;
use Erpify\Shared\Uuid\Domain\Uuid;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * {@link AuditActorAnonymiser} over the append-only `audit_log` via plain DBAL. The "forget me" erasure is
 * one parameterised UPDATE that overwrites a subject's `actor_id` with a single freshly minted UUIDv7,
 * redacts `ip`/`user_agent` to a sentinel and raises the materialised `actor_erased` flag — never a DELETE,
 * so the security trail survives. The original
 * id is neither stored nor derivable from the pseudonym, so the link to the person is broken irreversibly
 * (effective anonymisation, not keyed pseudonymisation that a leaked key could reverse). The narrowing
 * `WHERE actor_id = …` makes it idempotent: a re-run with the original id matches nothing.
 *
 * One UPDATE, no batching or advisory lock: a single subject's rows are bounded by that actor's own
 * activity, unlike the retention pruner that sweeps the whole table. `metadata` is deliberately left
 * untouched, on the ADR invariant that it carries no PII (only ids and discriminants) — revisit this
 * (grow a metadata redactor) the day an action stores personal data there.
 *
 * Both operations guard the id with {@see Uuid::ensure()} at this edge: the only caller (the CLI) already
 * validates, so this is defence in depth — a future caller that skips that check fails on a malformed id
 * with a domain error here rather than a raw driver exception from the `CAST(… AS UUID)`.
 */
#[AsAlias(AuditActorAnonymiser::class)]
final readonly class DbalAuditActorAnonymiser implements AuditActorAnonymiser
{
    /**
     * Replaces `ip`/`user_agent` on erasure. A sentinel, not NULL, so a GDPR-redacted value stays
     * distinguishable from one that was never captured (anonymous/system or off-request entries).
     */
    private const string REDACTED = '[REDACTED]';

    public function __construct(
        private Connection $connection,
    ) {
    }

    #[Override]
    public function countFor(string $actorId): int
    {
        Uuid::ensure($actorId);

        $count = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM audit_log WHERE actor_id = CAST(:actor_id AS UUID)',
            ['actor_id' => $actorId],
        );

        return \is_numeric($count) ? (int) $count : 0;
    }

    #[Override]
    public function anonymise(string $actorId): ActorAnonymisationResult
    {
        Uuid::ensure($actorId);

        $pseudonym = Uuid::generate();

        $affectedRows = (int) $this->connection->executeStatement(
            'UPDATE audit_log '
            . 'SET actor_id = CAST(:pseudonym AS UUID), ip = :redacted, user_agent = :redacted, '
            . 'actor_erased = TRUE '
            . 'WHERE actor_id = CAST(:actor_id AS UUID)',
            [
                'pseudonym' => $pseudonym,
                'redacted' => self::REDACTED,
                'actor_id' => $actorId,
            ],
        );

        return new ActorAnonymisationResult($pseudonym, $affectedRows);
    }
}
