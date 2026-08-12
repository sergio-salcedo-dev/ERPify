<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Erpify\Shared\Audit\Application\ActorAnonymisationResult;
use Erpify\Shared\Audit\Application\AuditActorAnonymiser;
use Erpify\Shared\Audit\Domain\AuditRedaction;
use Erpify\Shared\Uuid\Domain\Uuid;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * {@link AuditActorAnonymiser} over the append-only `audit_log` via plain DBAL. The "forget me" erasure is
 * one parameterised UPDATE that overwrites a subject's `actor_id` with a single freshly minted UUIDv7,
 * redacts `ip`/`user_agent` to {@see AuditRedaction::SENTINEL} and raises the materialised `actor_erased`
 * flag — never a DELETE, so the security trail survives. It writes that sentinel unguarded, over a never
 * captured value as readily as over a real one, which is sound HERE and only here: every row it matches
 * was authored by the one person being forgotten, so nothing it overwrites belonged to anybody else.
 * The original
 * id is neither stored nor derivable from the pseudonym, so the link to the person is broken irreversibly
 * (effective anonymisation, not keyed pseudonymisation that a leaked key could reverse). The narrowing
 * `WHERE actor_id = …` makes it idempotent: a re-run with the original id matches nothing.
 *
 * One UPDATE, no batching or advisory lock: a single subject's rows are bounded by that actor's own
 * activity, unlike the retention pruner that sweeps the whole table. `metadata` is deliberately left
 * untouched, on the ADR invariant that it carries no PII (only ids and discriminants) — revisit this
 * (grow a metadata redactor) the day an action stores personal data there.
 *
 * **The rows are taken in `id` order, and that clause is not decoration.** This statement has a caller that
 * holds no transaction and no prior lock: the `audit:gdpr:erase` CLI, which erases the actor axis on its
 * own. Concurrent with an identity erasure, the two overlap on every row where the CLI's actor is also the
 * erasure subject's resource, and a bare `WHERE` takes those in whatever order the planner chose — the
 * opposite one is a deadlock. Ordering each transaction's own acquisitions is enough HERE because this is a
 * single statement; it is not enough for the erasure, whose two passes span two statements and therefore
 * need {@see \Erpify\Shared\Audit\Application\AuditSubjectRowLock} over their union. Under that lock this
 * clause is redundant and free.
 *
 * Both operations guard the id with {@see Uuid::ensure()} at this edge: the only caller (the CLI) already
 * validates, so this is defence in depth — a future caller that skips that check fails on a malformed id
 * with a domain error here rather than a raw driver exception from the `CAST(… AS UUID)`.
 */
#[AsAlias(AuditActorAnonymiser::class)]
final readonly class DbalAuditActorAnonymiser implements AuditActorAnonymiser
{
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
            . 'WHERE id IN ('
            . 'SELECT id FROM audit_log WHERE actor_id = CAST(:actor_id AS UUID) ORDER BY id FOR UPDATE'
            . ')',
            [
                'pseudonym' => $pseudonym,
                'redacted' => AuditRedaction::SENTINEL,
                'actor_id' => $actorId,
            ],
        );

        return new ActorAnonymisationResult($pseudonym, $affectedRows);
    }
}
