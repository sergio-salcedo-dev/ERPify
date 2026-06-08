<?php

declare(strict_types=1);

namespace Erpify\Shared\Monitoring\Infrastructure\Sentry;

use Erpify\Shared\Application\Problem\RedactionDenylist;
use Sentry\Event;

/**
 * Sentry `before_send` callback: defense-in-depth scrubbing of sensitive fields
 * from every event before it leaves the process.
 *
 * Pairs with `send_default_pii: false` (which already keeps request headers,
 * cookies, IP and the authenticated user off the event by default). This is the
 * belt to those braces: it strips the {@see RedactionDenylist} keys
 * (password/token/secret/authorization/cookie/ssn/iban) — **recursively, at every
 * depth** — from the event's `extra` and the `request` sub-arrays (`data`,
 * `cookies`, `headers`, `env`), and from the raw `query_string`.
 *
 * Recursion matters: the SDK captures nested request bodies (`data['user']['password']`)
 * and `query_string` arrives as a raw `a=b&c=d` string, both of which a single-level
 * filter would miss. Out of scope (covered elsewhere or by the denylist's own limits):
 * breadcrumbs, exception messages, and secret-bearing keys NOT in the denylist.
 *
 * Reusing the SAME denylist as the RFC 9457 pipeline
 * ({@see \Erpify\Shared\Application\Problem\ProblemDetailsFactory}) keeps scrub
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

            // `query_string` is a raw "a=b&c=d" string in the SDK, not an array,
            // so it bypasses the loop above — parse it so denylisted params
            // (?token=…, ?password=…) are stripped before transmission.
            if (
                isset($request['query_string'])
                && \is_string($request['query_string'])
                && '' !== $request['query_string']
            ) {
                \parse_str($request['query_string'], $params);
                $request['query_string'] = \http_build_query($this->scrub($params));
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
