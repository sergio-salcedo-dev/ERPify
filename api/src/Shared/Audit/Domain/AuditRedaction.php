<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Domain;

/**
 * The value the audit trail stores in place of request metadata it has redacted.
 *
 * **A sentinel and not NULL**, so a redacted value stays distinguishable from one that was never
 * captured — an off-request write has no `ip` to begin with, and a request that carried no
 * `User-Agent` header has no value either. Collapsing both onto NULL would destroy the only evidence
 * that something was there and was removed on purpose.
 *
 * Whether a statement may write it over a NULL is that statement's own call, and the two differ for a
 * reason worth knowing. The actor-axis erasure matches only rows the erased person authored, so every
 * column it overwrites belonged to that one person and the sentinel misattributes nothing. The
 * resource-axis erasure matches a MIXED set — the subject's own anonymous rows beside rows an
 * administrator wrote about them — so it guards each column on being non-null: writing the sentinel
 * over a value that was never captured would manufacture evidence of a redaction that never happened,
 * on a row whose other columns are somebody else's.
 *
 * **This centralises the literal, never the licence to write it.** `docs/adr/audit-activity-log.md`
 * D4.1 asserts a compliance invariant over this exact spelling, so a second, drifted copy is a silent
 * compliance failure rather than a cosmetic duplication — which is what earns the extraction at only
 * two call sites. What it deliberately does not decide is WHICH rows may receive it: the two writers
 * are doing different things and each owns its own predicate. The actor-axis erasure redacts because
 * the person who acted is being forgotten; the resource-axis erasure redacts only where the acting
 * party was never identified, so the captured metadata may belong to the subject it names rather than
 * to a third party. Reading this constant is not an argument that a third statement may write it.
 */
final class AuditRedaction
{
    public const string SENTINEL = '[REDACTED]';

    /**
     * A namespace for one normative literal, never a value. Instantiating it would be meaningless, so it
     * is made impossible rather than merely pointless.
     */
    private function __construct()
    {
    }
}
