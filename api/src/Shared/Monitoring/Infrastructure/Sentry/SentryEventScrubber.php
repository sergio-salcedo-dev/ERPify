<?php

declare(strict_types=1);

namespace Erpify\Shared\Monitoring\Infrastructure\Sentry;

use Erpify\Shared\ErrorContract\Application\RedactionDenylist;
use Erpify\Shared\ErrorContract\Application\RequestUriRedaction;
use Sentry\Event;

/**
 * Sentry `before_send` callback: defense-in-depth scrubbing of sensitive fields
 * from every event before it leaves the process.
 *
 * Pairs with `send_default_pii: false` (which already keeps request headers,
 * cookies, IP and the authenticated user off the event by default). This is the
 * belt to those braces, in two vocabularies for two shapes:
 *
 *  - **Key names** — the {@see RedactionDenylist} keys (password/token/secret/
 *    authorization/cookie/ssn/iban) stripped **recursively, at every depth** from
 *    the event's `extra` and the `request` sub-arrays (`data`, `cookies`,
 *    `headers`, `env`). Recursion matters: the SDK captures nested request bodies
 *    (`data['user']['password']`) a single-level filter would miss.
 *  - **URIs** — {@see RequestUriRedaction} over `request.url` and
 *    `request.query_string`, which arrive as raw strings a key-based rule cannot
 *    read, and which carry the identity axes (`actorId`, `resourceId`,
 *    `correlationId`) and the positional `filters[N][value]` search grammar.
 *
 * Out of scope (covered elsewhere or by the denylist's own limits): breadcrumbs,
 * exception messages, and secret-bearing keys NOT in the denylist.
 *
 * Reusing the SAME denylist as the RFC 9457 pipeline
 * ({@see \Erpify\Shared\ErrorContract\Application\ProblemDetailsFactory}) keeps scrub
 * parity between the HTTP error body and the Sentry event.
 *
 * Lives under the `Shared/Monitoring` module: vendor-specific glue (`Sentry/`)
 * inside `Infrastructure/`, alongside the future `SentryTelemetry` adapter. Wired
 * in `config/packages/sentry.yaml` under `options.before_send` (a service
 * reference resolved by the SentryBundle). The bundle loads in dev + prod (not
 * test), so this runs only where events are actually transmitted.
 *
 * @api Consumed by the SentryBundle via a container reference (the `before_send`
 *      string in sentry.yaml), which static analysis cannot trace — so it is an
 *      entry point, not dead code.
 */
final class SentryEventScrubber
{
    /**
     * Request sub-arrays that may carry caller-controlled, denylist-named keys.
     */
    private const array REQUEST_KEYS = ['headers', 'cookies', 'data', 'env'];

    public function __invoke(Event $event): Event
    {
        /** @var array<string, mixed> $extra Recursive scrub preserves the string keys it keeps. */
        $extra = $this->scrub($event->getExtra());
        $event->setExtra($extra);

        $request = $event->getRequest();

        if ([] !== $request) {
            foreach (self::REQUEST_KEYS as $key) {
                if (isset($request[$key]) && \is_array($request[$key])) {
                    $request[$key] = $this->scrub($request[$key]);
                }
            }

            // `query_string` and `url` are raw strings in the SDK, not arrays, so the loop above never
            // reaches them — and a denylist is a rule about KEY names, the wrong shape for either. Both go
            // through the vocabulary the access log and the per-error log line already share, so an axis
            // redacted in one sink is redacted in all of them. `url` carries the query too (the SDK builds
            // it from the whole URI), so redacting only `query_string` would leave the same values on the
            // same event.
            //
            // Sentry is a third-party sink with its own retention that no erasure path can reach, which is
            // what makes an identity axis here outlive the erasure the application confirmed to the subject.
            if (isset($request['query_string']) && \is_string($request['query_string'])) {
                $request['query_string'] = RequestUriRedaction::redactQuery($request['query_string']);
            }

            if (isset($request['url']) && \is_string($request['url'])) {
                $request['url'] = RequestUriRedaction::redact($request['url']);
            }

            $event->setRequest($request);
        }

        return $event;
    }

    /**
     * Strips denylisted keys at every array depth.
     *
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    private function scrub(array $data): array
    {
        $filtered = RedactionDenylist::filter($data);

        foreach ($filtered as $key => $value) {
            if (\is_array($value)) {
                $filtered[$key] = $this->scrub($value);
            }
        }

        return $filtered;
    }
}
