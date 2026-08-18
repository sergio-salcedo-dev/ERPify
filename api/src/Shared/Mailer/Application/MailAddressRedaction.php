<?php

declare(strict_types=1);

namespace Erpify\Shared\Mailer\Application;

/**
 * Replaces every email address in a string with {@see SENTINEL}.
 *
 * An address is a person datum whose erasure nothing owns: the audit anonymisers rewrite `actor_id` and
 * `resource_id` and never touch a log line, and `RedactionDenylist` matches KEY names of a context map and
 * never looks inside a value. An address that reaches a log therefore outlives the erasure the application
 * confirmed to its subject, on a sink — `php://stderr` under Docker's json-file driver — that no compose file
 * gives a rotation, a TTL or an owner.
 *
 * **This is the second line of defence, not the first.** The failure this application raises for a mail
 * delivery composes its own message out of a class name, an SMTP code, an enhanced status code and an origin,
 * so no server-supplied text reaches a log to begin with; this pass runs over that composed message. The
 * division matters: if this pattern ever misses a form, the cost is a worse diagnostic rather than a leaked
 * address, because nothing that reaches it was carrying one. Named in prose rather than linked, because the
 * composer lives in `Infrastructure` and this layer does not point outward, even in a docblock.
 */
enum MailAddressRedaction
{
    /**
     * Spelled here rather than imported from the URI vocabulary. Each sink that redacts states its own token —
     * `api/frankenphp/Caddyfile`, the request-URI rule and the PWA's filter all do — so that the sinks agree on
     * what an operator reads without any one of them depending on another's constant.
     */
    public const string SENTINEL = 'REDACTED';

    /**
     * Anchored on `@`, with a local part and a domain drawn from the SAME class: everything except whitespace,
     * `@` itself and the RFC 5322 `specials` that delimit an address in prose (`()<>[]:;,"`). The dot is
     * deliberately IN the class, because it is legal in an atom and a domain is nothing but dotted atoms.
     *
     * Written this way because the narrow, RFC-shaped alternative fails toward the leak. A local part of
     * `[A-Za-z0-9._%+'-]` and a domain requiring a dot and a two-letter TLD misses every form the application
     * itself accepts and stores: a non-ASCII local part, an IDN domain, an atom containing braces, and a
     * single-character TLD each leave the byte before or after the `@` outside the class, so the pattern does
     * not match at all and the whole address survives. Over-redaction costs a diagnostic; under-redaction costs
     * an identifier that outlives its own erasure.
     *
     * **The lookbehind is insurance against a deployment without PCRE's JIT, not an optimisation of this one.**
     * Measured on a dotted run ending in `@`, at 200 KB: with `pcre.jit=1`, which is what this deployment runs,
     * the lookbehind COSTS 0.507 ms against 0.135 ms without it; with `pcre.jit=0` it is the difference between
     * 5 ms and 9.6 s. So the JIT already collapses the quadratic scan, and the lookbehind is what keeps that
     * true if the JIT is ever absent — a build without it, or a future `ini` that disables it. The possessive
     * quantifiers change nothing measurable either way; they are kept because they make the absence of internal
     * backtracking a property of the pattern rather than of the engine that happens to run it.
     *
     * Four forms are knowingly left alone. Two are addresses the application cannot send, because the strict
     * `#[Assert\Email]` mode the aggregate carries refuses them: an IP-literal domain (`alice@[192.168.1.10]`)
     * and a quoted local part containing an `@` (`"a@b"@example.test`). The other two are addresses that are no
     * longer spelled as one — percent-encoded (`alice%40example.test`) and MIME-encoded
     * (`=?utf-8?B?YWxpY2VAZXhhbXBsZS50ZXN0?=`) — which have no `@` for the pattern to anchor on.
     *
     * That last pair bounds what this class promises. It reads a message THIS APPLICATION composed, where an
     * encoded address cannot appear; it is not a general-purpose scrubber for arbitrary text, and using it as
     * one would need the inventory extended rather than assumed.
     */
    private const string PATTERN = '/(?<![^\s@()<>\[\]:;,"])[^\s@()<>\[\]:;,"]++@[^\s@()<>\[\]:;,"]++/';

    public static function apply(string $value): string
    {
        if (!\str_contains($value, '@')) {
            return $value;
        }

        $redacted = \preg_replace(self::PATTERN, self::SENTINEL, $value);

        // No input reaches this: there is no `/u` modifier, so a malformed-UTF-8 subject cannot fail, and the
        // limits hold at the configured values against subjects of megabytes. It is reachable by CONFIGURATION
        // — a lowered `pcre.backtrack_limit` makes the JIT return null on a large subject — which is why the
        // branch exists rather than being asserted away. The fallback resolves the failure against the subject
        // rather than the operator and calls no regex engine of its own.
        return $redacted ?? self::blankEveryTokenHoldingAnAt($value);
    }

    /**
     * The same rule with a coarser boundary: a run of address bytes becomes whatever sits between two spaces.
     * Measured, that is not a whitespace normaliser — runs of spaces survive, and a line with no `@` comes back
     * byte for byte, tabs and newlines included. What it does cost is precision when an address is delimited by
     * a tab or a newline rather than a space: the whole token goes, and words on the far side of the newline go
     * with it (`"a\tb@x.test\nc  d"` becomes `"REDACTED  d"`). That is the safe direction for a branch that
     * only runs once the engine has already failed.
     */
    private static function blankEveryTokenHoldingAnAt(string $value): string
    {
        return \implode(' ', \array_map(
            static fn (string $token): string => \str_contains($token, '@') ? self::SENTINEL : $token,
            \explode(' ', $value),
        ));
    }
}
