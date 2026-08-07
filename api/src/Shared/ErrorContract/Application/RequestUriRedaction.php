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
 * unable to tell a filtered request from an unfiltered one. The same token Caddy's access-log filter writes,
 * so both logs read alike.
 *
 * **Vocabulary parity with `api/frankenphp/Caddyfile`, with one deliberate difference.** Caddy's grammar has
 * no wildcard, so it enumerates `filters[0..8][value]` and a tenth axis would escape it. Here the grammar is
 * a pattern, so no index can outgrow it, and the `filters[N][value][]` form the `in` operator would emit is
 * covered too — that shape costs nothing on this side, whereas at the edge it would be nine more lines for a
 * form no field mapping currently admits.
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

    /** The value axis of the positional search grammar, scalar and `in` forms. */
    private const string SEARCH_VALUE_KEY = '/\Afilters\[\d+\]\[value\](\[\])?\z/';

    /** Bound on the decode loop below, so a key of stacked `%25`s cannot spin it. */
    private const int MAX_DECODE_PASSES = 4;

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

        return \substr($requestUri, 0, $separator + 1) . self::redactQuery($query);
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
        return \implode('&', \array_map(self::redactPair(...), \explode('&', $query)));
    }

    /**
     * A pair with no `=` carries no value, so it is left alone: there is nothing to leak, and rewriting it
     * would corrupt a shape the operator needs to recognise the request.
     */
    private static function redactPair(string $pair): string
    {
        $equals = \strpos($pair, '=');

        if (false === $equals) {
            return $pair;
        }

        $key = \substr($pair, 0, $equals);

        return self::isSensitive($key) ? $key . '=' . self::SENTINEL : $pair;
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
        $candidate = \strtolower($key);

        for ($pass = 0; $pass <= self::MAX_DECODE_PASSES; ++$pass) {
            if (self::namesASensitiveAxis($candidate)) {
                return true;
            }

            $decoded = \strtolower(\urldecode($candidate));

            if ($decoded === $candidate) {
                return false;
            }

            $candidate = $decoded;
        }

        return false;
    }

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
}
