<?php

declare(strict_types=1);

namespace Erpify\Shared\ErrorContract\Application;

/**
 * Redacts the sensitive values out of a request URI's query string before it is logged.
 *
 * The per-error log line carries `request_uri` so an operator can see which request failed, and
 * {@see RedactionDenylist} cannot protect it: that filter matches KEY names of a context map and never
 * looks inside a value, so the whole query string travelled into the record intact. Two families of value
 * make that a leak rather than a verbosity problem:
 *
 *  - Secrets — a single-use `?token=<id>.<secret>` on a failed accept/reset, a Mercure `?authorization=`.
 *  - Person ids — the audit screen filters by `actorId`/`resourceId`, and fires the same identities at the
 *    API under the positional `filters[N][value]` search grammar.
 *
 * The sink is what makes it matter. In prod Monolog writes to `php://stderr` behind a `fingers_crossed`
 * handler, so one 5xx flushes the whole buffer — WARNING lines from unrelated 4xx included — into the
 * default json-file Docker driver, which no compose file gives a rotation, a TTL, or an owner. Nothing in
 * the erasure path can reach it, so a person id logged here outlives the erasure the application confirmed
 * to the subject.
 *
 * **Sentinel, not strip.** The value is replaced with {@see SENTINEL}, deliberately unlike the map filter's
 * strip semantics: a URI's diagnostic value IS its shape, and dropping the pair would leave an operator
 * unable to tell a filtered request from an unfiltered one. The access log answers a different way — it
 * keeps no query string at all — so the shape survives here and nowhere else.
 *
 * **Names are not the only place an identifier hides.** Every rule here matches a parameter NAME, and that
 * is a whole class short: an expired session redirects to `/login?next=<the entire audit URL, percent-
 * encoded>`, so `actorId` and `resourceId` reach the log under the name `next`, which no denylist will ever
 * contain. A value that is itself a URI with a query is therefore followed and redacted by the same rules —
 * see {@see redactNestedUri()} for the bound and for why a value with nothing to redact is returned byte for
 * byte.
 *
 * **The access log is not a peer of this rule and no longer tries to be.** It used to enumerate the same
 * vocabulary by name, and this class justified declining to cover the `in` operator's `filters[N][value][]`
 * spelling at the edge on the grounds that no field mapping admitted it. That was false when it was written —
 * `FieldMapping`'s default operator set granted `In` to every field that named none, `email` among them — and
 * the spelling reached the access log in clear. Caddy now drops the query string whole, so there is nothing
 * there to keep in step with: the two sinks differ in kind, not in vocabulary.
 *
 * That leaves this rule as the one that has to be a pattern rather than a list, because its sink keeps the
 * query: no index can outgrow it, the `[]` and `[N]` sub-index spellings are both covered, and a value is
 * decoded repeatedly before it is matched.
 */
enum RequestUriRedaction
{
    public const string SENTINEL = 'REDACTED';

    /**
     * Exact, case-insensitive. These are identity axes rather than secrets, and they are NOT folded into
     * {@see RedactionDenylist::KEYS} on purpose: that list is matched as a substring against problem-details
     * extension keys as well, and `actorId`/`resourceId`/`correlationId` are Resource DTO property names, so
     * adding them there would silently start stripping fields out of response bodies.
     *
     * `correlationId` holds no person id — it is a request-correlation UUID. It is redacted because the
     * trail of one reconstructs a session.
     */
    public const array IDENTITY_KEYS = ['actorid', 'resourceid', 'correlationid'];

    /**
     * The value axis of the positional search grammar. The index is permissive — empty, negative, any
     * width — and so is the array suffix, which the wire spells `[]`, `[0]`, `[12]` depending on whether
     * the caller used `http_build_query`, axios or jQuery. A log is the one place where over-matching is
     * the safe direction: the cost is a diagnostic, and the cost of the other direction is an identifier
     * that outlives its own erasure.
     */
    private const string SEARCH_VALUE_KEY = '/\Afilters\[-?\d*\]\[value\](\[-?\d*\])?\z/';

    /** Bound on the decode loops below, so a key or value of stacked `%25`s cannot spin them. */
    private const int MAX_DECODE_PASSES = 4;

    /**
     * Whitespace and control bytes, which pad a parameter name without changing what it names. PCRE's `\s`
     * is ASCII-only, so the Unicode separators are named explicitly — `%C2%A0`, `%E2%80%80` and the BOM are
     * padding a caller can spell just as easily as a space, and the PWA's JS `\s` already covers them.
     */
    private const string PADDING_BYTES = '/(?:[\s\x00-\x1F\x7F\x{FEFF}]|\p{Z})+/u';

    /** The same class without `/u`, for a key whose bytes are not valid UTF-8 and would fail the above. */
    private const string ASCII_PADDING_BYTES = '/[\s\x00-\x1F\x7F]+/';

    /**
     * How far a URI carried inside a value is followed. One level reaches `?next=<the whole audit URL>`,
     * which is the shape that exists; the bound is what keeps a value of nested encoded queries from
     * turning this into unbounded work.
     */
    private const int MAX_NESTED_URI_DEPTH = 2;

    public static function redact(string $requestUri): string
    {
        $separator = \strpos($requestUri, '?');

        if (false === $separator) {
            return $requestUri;
        }

        $query = \substr($requestUri, $separator + 1);

        if ('' === $query) {
            return $requestUri;
        }

        return \substr($requestUri, 0, $separator + 1) . self::redactQueryAtDepth($query, 0);
    }

    /**
     * The query on its own, for the sinks that carry it detached from its URI: a Sentry event splits
     * `request.url` from `request.query_string`, and both have to answer to the same vocabulary or an axis
     * redacted on one arrives intact on the other.
     *
     * Order and encoding are preserved. Normalising through `parse_str()`/`http_build_query()` would re-spell
     * `filters[0][value]` as the nested keys `filters`, `0`, `value` — which no rule here matches, so the
     * positional grammar would pass straight through — and would show an operator a query the caller never
     * sent.
     */
    public static function redactQuery(string $query): string
    {
        return self::redactQueryAtDepth($query, 0);
    }

    private static function redactQueryAtDepth(string $query, int $depth): string
    {
        return \implode('&', \array_map(
            static fn (string $pair): string => self::redactPair($pair, $depth),
            \explode('&', $query),
        ));
    }

    /**
     * A pair with no `=` carries no value, so it is left alone: there is nothing to leak, and rewriting it
     * would corrupt a shape the operator needs to recognise the request.
     *
     * A key that names nothing sensitive still has to answer for what it CARRIES. Every rule above matches
     * a parameter NAME, and the leak this misses is an identifier riding inside a value: an expired session
     * redirects to `/login?next=<the whole audit URL, percent-encoded>`, so the ids the audit screen holds
     * arrive under the name `next`, which no list will ever contain.
     */
    private static function redactPair(string $pair, int $depth): string
    {
        $equals = \strpos($pair, '=');

        if (false === $equals) {
            return $pair;
        }

        $key = \substr($pair, 0, $equals);

        if (self::isSensitive($key)) {
            return $key . '=' . self::SENTINEL;
        }

        $nested = self::redactNestedUri(\substr($pair, $equals + 1), $depth);

        return null === $nested ? $pair : $key . '=' . $nested;
    }

    /**
     * A value that is itself a URI with a query, redacted by the same vocabulary and re-encoded.
     *
     * Returns `null` — meaning "keep the caller's bytes exactly" — whenever there is nothing to redact:
     * no query inside, or a query the rules leave untouched. That is what keeps the file's own invariant
     * true for every request that is not leaking; only a value that WAS carrying an identifier is rewritten,
     * and there the redaction is worth more than byte fidelity. An empty query needs no case of its own:
     * the rules leave it identical, so the same comparison already answers it.
     *
     * The `false === $separator` guard is a statement of its own because that is what narrows `strpos()`'s
     * `int|false` down to the `int` both `substr()` offsets below are typed against.
     */
    private static function redactNestedUri(string $encodedValue, int $depth): ?string
    {
        if ($depth >= self::MAX_NESTED_URI_DEPTH) {
            return null;
        }

        $decoded = self::decodeUntilQuerySurfaces($encodedValue);
        $separator = \strpos($decoded, '?');

        if (false === $separator) {
            return null;
        }

        $query = \substr($decoded, $separator + 1);
        $redactedQuery = self::redactQueryAtDepth($query, $depth + 1);

        return $redactedQuery === $query
            ? null
            : \rawurlencode(\substr($decoded, 0, $separator + 1) . $redactedQuery);
    }

    /**
     * A value is decoded until a query surfaces, not once. {@see isSensitive()} reads a KEY through up to
     * {@see MAX_DECODE_PASSES} decodings, and a value that stopped at one left the two halves of the same
     * rule disagreeing: `?next=%252Fa%253FactorId%253D<id>` has no literal `?` after a single decode, so
     * the nested URI was never followed and the id travelled intact under a name no denylist holds.
     *
     * The first decoding is unconditional, so every value that already surfaced a query at one level takes
     * exactly the path it took before; the loop only reaches inputs that used to return early with nothing
     * found. Deeper spellings therefore only ever cause MORE redaction, never less.
     */
    private static function decodeUntilQuerySurfaces(string $encodedValue): string
    {
        $candidate = \urldecode($encodedValue);

        for ($pass = 0; $pass < self::MAX_DECODE_PASSES; ++$pass) {
            if (\str_contains($candidate, '?')) {
                return $candidate;
            }

            $decoded = \urldecode($candidate);

            if ($decoded === $candidate) {
                return $candidate;
            }

            $candidate = $decoded;
        }

        return $candidate;
    }

    /**
     * The key arrives percent-encoded, which is how the positional grammar travels on the wire
     * (`filters%5B0%5D%5Bvalue%5D`); matching the raw bytes would see none of it.
     *
     * Decoding is repeated until the key stops changing, because a single pass leaves
     * `filters%255B0%255D%255Bvalue%255D` as `filters%5b0%5d%5bvalue%5d`, which matches nothing. No producer
     * emits that — it is a URI a caller crafted — but the sink records what the caller sent, not what the
     * producer meant, so the rule has to read it too. Over-redacting a log costs a diagnostic; under-redacting
     * it costs an identifier that outlives its own erasure. The pass count is capped so a key of stacked
     * `%25`s cannot spin here.
     */
    private static function isSensitive(string $key): bool
    {
        $candidate = self::reduce($key);

        for ($pass = 0; $pass <= self::MAX_DECODE_PASSES; ++$pass) {
            if (self::namesASensitiveAxis($candidate)) {
                return true;
            }

            $decoded = self::reduce(\urldecode($candidate));

            if ($decoded === $candidate) {
                return false;
            }

            $candidate = $decoded;
        }

        // The candidate the last iteration decoded but did not get to test. Without this the bound is one
        // decoding short of what it advertises, and a key encoded one level deeper than the cap walks past.
        return self::namesASensitiveAxis($candidate);
    }

    /**
     * The identity axes are matched WHOLE, so one byte of padding is enough to miss them: `?actorId%00=`,
     * `?actorId%0A=`, `?actorId%20=` and `?actor+Id=` all decode to something that is not `actorid`, and
     * all four still reach this sink. The request answers 4xx — no DTO property matches the padded name —
     * and 4xx is precisely what the `fingers_crossed` buffer holds and flushes on the next 5xx, so the
     * value rides into the log under a key the rule declined to recognise.
     *
     * Whitespace and control bytes carry no meaning in a parameter name, so removing them before matching
     * costs nothing a reader needs and closes the padding class in one rule rather than one per byte.
     */
    private static function namesASensitiveAxis(string $key): bool
    {
        if (\in_array($key, self::IDENTITY_KEYS, true)) {
            return true;
        }

        if (1 === \preg_match(self::SEARCH_VALUE_KEY, $key)) {
            return true;
        }

        return \array_any(
            RedactionDenylist::KEYS,
            static fn (string $deniedKey): bool => \str_contains($key, $deniedKey),
        );
    }

    /**
     * A key reduced to what it actually names: lower-cased and stripped of padding. The reduced form is what
     * the next decoding starts from, not just what the comparison sees — an escape split by padding
     * (`%250%0A0actorId`) only heals into a decodable one once the padding is gone.
     */
    private static function reduce(string $key): string
    {
        $lowered = \strtolower($key);

        return \preg_replace(self::PADDING_BYTES, '', $lowered)
            ?? \preg_replace(self::ASCII_PADDING_BYTES, '', $lowered)
            ?? $lowered;
    }
}
