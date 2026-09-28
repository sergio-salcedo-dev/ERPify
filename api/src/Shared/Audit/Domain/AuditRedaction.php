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
 *
 * **The literal is reserved to those two statements, and capture is where that is enforced.** `user_agent`
 * is free text straight from the `User-Agent` header: a client sending `[REDACTED]` would otherwise produce a
 * row that, to a person reading it, looks redacted when no erasure touched it. The flags already settle it
 * for a query, on every row and retroactively: a sentinel is an erasure's exactly where `actor_erased` is
 * TRUE or `resource_erased` is TRUE with `actor_type = 'anonymous'`, because each pass overwrites every
 * non-blank value in the columns it redacts — a forged literal on a row a pass touched was overwritten
 * anyway, and every other row falls outside the predicate. What this rule buys is the reader who does not
 * check the flags. `ip` comes from `getClientIp()`, which already drops anything failing
 * `FILTER_VALIDATE_IP`, so neutralising it too is defence in depth. {@see neutraliseCaptured()} rewrites
 * such a value with a prefix rather than nulling it — NULL means "never captured", and a header did arrive.
 * The prefix is itself forgeable: it guarantees only that the stored value differs from the sentinel, never
 * that the server minted it. Rows persisted before this rule shipped were not rewritten and may still hold
 * a client-sent literal, which the flag predicate above still attributes correctly.
 */
final class AuditRedaction
{
    public const string SENTINEL = '[REDACTED]';

    public const string CLIENT_SUPPLIED_PREFIX = '[client-supplied] ';

    /**
     * A namespace for normative literals, never a value. Instantiating it would be meaningless, so it
     * is made impossible rather than merely pointless.
     */
    private function __construct()
    {
    }

    /**
     * Guarantees a CAPTURED request value never equals the sentinel exactly. Only ASCII surrounding
     * whitespace and ASCII case are folded (`trim()`, `strcasecmp()`), so `  [redacted] ` is neutralised
     * too; Unicode look-alikes — NBSP or zero-width padding, full-width brackets — pass untouched and are
     * not defended here. The value is trimmed before it is prefixed, so one padded to the column width
     * cannot overflow `VARCHAR(512)`. Anything else, the sentinel embedded in a longer value included,
     * passes untouched: it already differs from the literal the erasure statements write.
     */
    public static function neutraliseCaptured(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $trimmed = \trim($value);

        if (0 !== \strcasecmp($trimmed, self::SENTINEL)) {
            return $value;
        }

        return self::CLIENT_SUPPLIED_PREFIX . $trimmed;
    }
}
