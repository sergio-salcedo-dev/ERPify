<?php

declare(strict_types=1);

namespace Erpify\Shared\Event\Application;

/**
 * GDPR erasure over the reproducible business log — the sanctioned mutation `event_store` admits, and today
 * the only one. `docs/adr/event-store-and-projections.md` D12 is the decision: the log stops being strictly
 * immutable and becomes append-only with a closed set of first-class mutations, which is the shape
 * `docs/adr/audit-activity-log.md` already adopted for the sibling log of PII.
 *
 * It exists because `PersistDomainEventMiddleware` appends **every** dispatched event before Messenger picks a
 * transport — routed, unrouted, sync alike — so for an event whose aggregate IS a person the `aggregate_id`
 * **is** that person's real identifier, and nothing in the erasure chain reached this table. The subject's id
 * outlived their own erasure, permanently.
 *
 * **It erases by VALUE, never by enumerating events or keys**, and that is the load-bearing part. The
 * inventory of "which events carry a person's id" has been wrong twice — it missed `SessionRevoked`, then
 * `AllSessionsRevoked` and `OtherSessionsRevoked` — and a list that has failed twice is not a foundation for
 * an erasure. A predicate on the value reaches every event that contains it, present and future, with no
 * producer having to remember anything. The preventive alternative — that the id never be written — depends
 * on the memory of whoever adds the next event and would need a gate of its own: more machinery for a weaker
 * guarantee.
 *
 * **One pseudonym for every row of one subject, and the reason is NOT the stream UNIQUE.** That constraint
 * spans `(tenant_id, aggregate_id, aggregate_version)`, `tenant_id` is written `NULL` on every row, and
 * Postgres compares nulls as distinct — so it imposes nothing today, measured against `pg_indexes` rather
 * than the migration and recorded in `deferred-work.md`. The reasons that do hold are two: one person must
 * not split into several anonymous identities, which is why the pseudonym is the one the actor pass already
 * minted for the audit axes; and a subject's events share one version sequence under a single `aggregate_id`
 * — the identity events and the two bulk session revokes alike — so moving them apart would scatter a
 * coherent stream even while no constraint complained.
 *
 * Idempotent by construction: a second pass matches nothing, because the value it searched for is gone. That
 * is not the same as saying nothing can reintroduce it — a transaction that loaded the subject before this
 * ran can still append afterwards, which is a known asynchronous-resurrection defect of the erasure chain
 * and not something this statement can close.
 *
 * **It never learns which identifiers denote people, and the caller must.** Matching by value is what makes
 * it reach every event, and equally what makes it reach every aggregate: handed an identifier that denotes a
 * bank, it rewrites that bank's stream just as willingly. That is deliberate — the shared module has no
 * business classifying persons, the same assignment `docs/adr/audit-activity-log.md` D4 makes for the audit
 * trail's resource axis — so establishing that the argument denotes a person is a precondition owned by the
 * context that owns people, and the erasure that calls this satisfies it before calling.
 */
interface EventStoreSubjectAnonymiser
{
    /**
     * Irreversibly rewrites every occurrence of `$subjectId` in the log to `$pseudonym` — a UUID minted fresh
     * in the erasure, with no original value, no mapping table and no deterministic derivation (D4 of
     * `docs/adr/audit-activity-log.md` vetoes the last two by name).
     *
     * @return int rows the erasure rewrote
     */
    public function anonymise(string $subjectId, string $pseudonym): int;
}
