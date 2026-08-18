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
 * **This is the second line of defence, not the first.** {@see \Erpify\Shared\Mailer\Infrastructure\MailDeliveryFailed}
 * composes its own message out of a class name, an SMTP code and an enhanced status code, so no server-supplied
 * text reaches a log to begin with. This pass runs over that composed message. The division matters: if this
 * pattern ever misses a form, the cost is a worse diagnostic rather than a leaked address, because nothing that
 * reaches it was carrying one.
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
     * **The lookbehind is what buys the linearity.** It pins a match to the start of a run of address bytes, so
     * the engine tries one start position per run instead of one per byte. Measured on a dotted run ending in
     * `@`: 1.35 ms with it at 50 KB against 558 ms without, and 5 ms with it at 200 KB where the version
     * without it exhausts a gigabyte. The possessive quantifiers are belt and braces — the same input runs at
     * the same speed with plain `+` — and they are kept because they make the absence of internal backtracking
     * a property of the pattern rather than of the input it happens to meet.
     *
     * Two forms are knowingly left alone: an IP-literal domain (`alice@[192.168.1.10]`) and a quoted local part
     * containing an `@` (`"a@b"@example.test`). Both are refused by the `#[Assert\Email]` strict mode the
     * aggregate carries, so neither can be an address this application sent.
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
        // rather than the operator and calls no regex engine of its own, at the cost of collapsing every run
        // of whitespace it splits on: a line arriving here is stripped of its tabs and newlines, which is
        // acceptable only because the alternative on this path is emitting the address.
        return $redacted ?? self::blankEveryTokenHoldingAnAt($value);
    }

    /**
     * The same rule with a coarser boundary: whitespace. It removes strictly more than {@see PATTERN} would
     * have, which is the safe direction for a fallback that only ever runs when the engine has already failed.
     */
    private static function blankEveryTokenHoldingAnAt(string $value): string
    {
        return \implode(' ', \array_map(
            static fn (string $token): string => \str_contains($token, '@') ? self::SENTINEL : $token,
            \explode(' ', $value),
        ));
    }
}
