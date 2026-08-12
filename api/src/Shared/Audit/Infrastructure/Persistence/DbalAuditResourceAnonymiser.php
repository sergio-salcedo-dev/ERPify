<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Erpify\Shared\Audit\Application\AuditResourceAnonymiser;
use Erpify\Shared\Audit\Domain\ActorType;
use Erpify\Shared\Audit\Domain\AuditRedaction;
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
 * **What it leaves alone, and the one exception.** `actor_id` and `actor_erased` are never written: they
 * describe the party that *acted*, which is not the person this statement erases, and raising `actor_erased`
 * on such a row would report that party as an erased actor, corrupting the very flag
 * `docs/adr/audit-activity-log.md` D4.1 materialised to make erasure queryable. `ip` and `user_agent` are
 * left alone too — **except where `actor_type` is `anonymous`**. The rule for an identified actor rests on
 * knowing WHOSE the metadata is: it is that actor's, and it is not the subject's. On a never-identified
 * actor nobody knows, and that is the whole difference. The address is the requester's, and on the two live
 * writers of this shape — the failed-login lockout and the recovery throttle — the requester may be the
 * subject triggering their own lockout or recovery, or a stranger doing it to them. The row records no
 * discriminant and the statement cannot invent one, so this errs toward erasure, and the cost is real and
 * stated here rather than hidden: where the requester WAS a stranger, their address is destroyed too, and
 * `actor_erased` stays FALSE so nothing in the trail even records that a value was removed.
 *
 * Erring the other way is not the safe default it looks like. {@see
 * \Erpify\Shared\Audit\Application\PersonResourceReferences} reads only `resource_erased = FALSE` rows, so
 * the pass that pseudonymises `resource_id` is precisely what removes the row from every detective control's
 * sight: an address left here is one no reconciliation can ever surface again. It is fixed here or nowhere.
 *
 * **The predicate is `actor_type`, never `actor_id IS NULL`.** The latter also matches `system` rows. Those
 * carry no request metadata in practice, but only because two classes independently read the same
 * `RequestStack` — {@see \Erpify\Iam\Identity\Infrastructure\Security\SecurityActorContextFactory} to choose
 * the type and {@see \Erpify\Shared\Audit\Infrastructure\SealedAuditEntryFactory} to seal the columns — and
 * no guard or test ties the two together. The enum token cannot silently widen the way that coupling can.
 * Each column is additionally guarded on actually holding something, so a value that was never captured is
 * not rewritten into evidence of a redaction. Both empty spellings need covering and neither is
 * hypothetical: an off-request or header-less capture leaves NULL, while
 * {@see \Erpify\Shared\Audit\Infrastructure\SealedAuditEntryFactory} only null-checks the header, so a
 * request sending a bare `User-Agent:` seals `''`. `COALESCE(col, :blank) <> :blank` is one predicate
 * covering both, deliberately in place of `col IS NOT NULL AND col <> :blank` — there the first conjunct is
 * dead, since `NULL <> ''` is already unknown and falls to the `ELSE`, and a dead conjunct reads as a guard
 * while no mutation of it can ever redden. See {@see AuditRedaction} for why the two axes differ here.
 *
 * **The `CASE` lives in the `SET` and must stay there.** Moved into the `WHERE` it would become a filter on
 * the whole mutation and stop pseudonymising `resource_id` on the administrator-written rows — deleting
 * behaviour that is correct today. Those rows are why the `WHERE` matches on `(resource_type, resource_id)`
 * and never on who wrote the row: several files name a person as an audit resource, and a sibling writer's
 * rows are erased exactly like the erasure's own. `PersonResourceErasureGateTest` bounds WHERE those writers
 * may live — the module declared to erase the type — rather than how many there are.
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
            . 'SET resource_id = CAST(:pseudonym AS UUID), resource_erased = TRUE, '
            . 'ip = CASE WHEN actor_type = :anonymous AND COALESCE(ip, :blank) <> :blank '
            . 'THEN :redacted ELSE ip END, '
            . 'user_agent = CASE WHEN actor_type = :anonymous '
            . 'AND COALESCE(user_agent, :blank) <> :blank THEN :redacted ELSE user_agent END '
            . 'WHERE resource_type = :resource_type AND resource_id = CAST(:resource_id AS UUID)',
            [
                'pseudonym' => $pseudonym,
                'anonymous' => ActorType::ANONYMOUS->value,
                'redacted' => AuditRedaction::SENTINEL,
                'blank' => '',
                'resource_type' => $resource->type,
                'resource_id' => $resource->id,
            ],
        );
    }
}
