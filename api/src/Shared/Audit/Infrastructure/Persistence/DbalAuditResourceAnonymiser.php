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
 * actor nobody knows, and that is the whole difference. The address is the requester's, and on the writers
 * of this shape today — the failed-login lockout and the recovery throttle — the requester may be the
 * subject triggering their own lockout or recovery, or a stranger doing it to them. That census is a fact
 * about the tree and not a property of the statement: the generic producer is
 * {@see \Erpify\Shared\Audit\Infrastructure\Http\EventListener\AccessLogAuditListener}, which names whatever
 * resource type a route's `_audit_resource_type` default declares, so a route opened on a person type would
 * join them silently. The row records no discriminant and the statement cannot invent one — the requester is
 * unauthenticated and supplies only a CLAIMED identity. None is sealed at write time today either, and
 * minting one would be its own change with its own cost: the only candidate is a heuristic (does the address
 * match one the subject has held on an `iam_session`?) bought by making the audit writer read another
 * context's PII at capture time. Weighed and not taken — not impossible. This therefore errs toward
 * erasure, and the cost is real and stated here rather than hidden: where the requester WAS a stranger,
 * their address is destroyed too, and unlike the actor pass — which only ever matches rows the erased person
 * authored — that is a capability no administrator had before. What survives is the FACT and not the value,
 * though not for the reason it first appears: `ip` IS client-influenced, since `getClientIp()` honours
 * `X-Forwarded-For` from any hop inside `SYMFONY_TRUSTED_PROXIES`, but Symfony discards every forwarded value
 * failing `FILTER_VALIDATE_IP`, so the sentinel specifically cannot be forged into that column. A sentinel
 * there can therefore only have come from one of the two passes, and `actor_erased` tells them apart:
 * `TRUE` is the actor pass, `FALSE` alongside `resource_erased = TRUE` is this one. `user_agent` has no such
 * filter and IS forgeable as the literal, so the inference rests on `ip` alone.
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
 * request sending a bare `User-Agent:` seals `''`. **`col <> :blank` alone covers both**, and the brevity is
 * the point rather than a saving: inside a `CASE WHEN`, `NULL <> ''` is unknown and unknown falls to the
 * `ELSE` exactly as false does, so the NULL case is already handled. Any wrapper making that explicit —
 * `col IS NOT NULL AND …`, or `COALESCE(col, :blank) <> :blank` — adds a term that reads as a guard while no
 * mutation of it can ever redden, which is the defect, not the documentation. What proves the guard instead
 * is a seeded row per column per spelling: delete `<> :blank` from either arm and the NULL row and the
 * empty-string row both go red. See {@see AuditRedaction} for why the two axes differ here.
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
            . 'ip = CASE WHEN actor_type = :anonymous AND ip <> :blank '
            . 'THEN :redacted ELSE ip END, '
            . 'user_agent = CASE WHEN actor_type = :anonymous '
            . 'AND user_agent <> :blank THEN :redacted ELSE user_agent END '
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
