<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Application;

use Erpify\Shared\Audit\Domain\AuditResource;

/**
 * GDPR erasure over the *resource* axis of the append-only trail — the third sanctioned mutation on
 * `audit_log`, beside the retention pruner's DELETE and {@see AuditActorAnonymiser}'s actor rewrite.
 *
 * It exists because those two axes answer different questions and can name the same person. The actor
 * anonymiser forgets *who acted*; this forgets *who was acted upon*. Leaving the second unforgotten is not
 * cosmetic: when a subject both acts and is the resource of the same row — a user changing their own roles —
 * erasing only the actor leaves the fresh pseudonym beside the real id in one row, a reversible crosswalk
 * that re-attributes every other anonymised row of that person through the indexed `resourceId` filter.
 *
 * **This module never decides which resource types denote a natural person.** The caller passes the type,
 * because that knowledge belongs to the bounded context that owns the person — the assignment
 * `docs/adr/audit-activity-log.md` D4 makes, and which this port implements rather than reverses. Keeping it
 * a separate port from {@see AuditActorAnonymiser} is the same decision at the interface level: the two
 * erasures are distinct legal operations and are never merged (`docs/adr/regulatory-audit-trail.md` D15),
 * so the actor interface never grows a resource verb and its CLI stays provably single-statement.
 */
interface AuditResourceAnonymiser
{
    /**
     * Irreversibly rewrites `$resource`'s id to `$pseudonym` across every row naming it, and raises the
     * materialised `resource_erased` flag. Idempotent — a second pass with the original id matches nothing.
     *
     * The pseudonym is an **input**, not minted here: the caller has already anonymised the same subject's
     * actor rows and passes that pseudonym, so both axes of one person collapse onto one anonymous identity
     * and intra-subject correlation survives the erasure. Minting a second pseudonym would split one person
     * into two anonymous actors for no gain.
     *
     * @return int rows whose `resource_id` was rewritten
     */
    public function anonymise(AuditResource $resource, string $pseudonym): int;
}
