<?php

declare(strict_types=1);

namespace Erpify\Shared\Monitoring\Infrastructure\Sentry;

use Erpify\Shared\ErrorContract\Application\EmailAddressRedaction;
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
 *  - **Addresses in free text** — {@see EmailAddressRedaction} over every string
 *    in `extra`, over the event's own message, and over
 *    `exception.values[].value`, which is the exception MESSAGE and the field the
 *    tracker titles an issue with. That last one is not hypothetical: the console
 *    error listener is registered at priority -64 (measured on the compiled prod
 *    container, ahead of Symfony's own at -128), {@see SentryEventFilter} discards
 *    only `ClientError` and messenger-worker transport noise — an
 *    `RfcComplianceException` is neither — and `bin/console mailer:test <address>`
 *    raises one whose message quotes the argument verbatim, so
 *    without this pass a person's address lands in third-party retention that no
 *    erasure path reaches. The application's own mail failure composes its message
 *    instead of copying one, which is what keeps this pass expected to find
 *    nothing on that path rather than relied upon there.
 *
 * The exception TYPE is deliberately left alone. It is a class name, and the one
 * form of it holding an `@` is an anonymous class, whose name is a file path and
 * not a person — redacting it would cost the only identification the frame has.
 *
 * Out of scope (covered elsewhere or by the denylist's own limits): breadcrumbs,
 * `contexts`, stack-trace frames, and secret-bearing keys NOT in the denylist.
 * The message pass has no live producer today — nothing under `src` calls
 * `captureMessage()` and the Monolog handler in `config/packages/monolog.yaml`
 * is commented out — so it guards the shape rather than a measured leak.
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
        /** @var array<string, mixed> $extra */
        $extra = $this->redactAddressesIn($extra);

        $event->setExtra($extra);

        $request = $event->getRequest();

        if ([] !== $request) {
            $event->setRequest($this->scrubRequest($request));
        }

        $this->redactExceptionValuesOf($event);
        $this->redactMessageOf($event);

        return $event;
    }

    /**
     * A vendor exception message is a general carrier of caller data, and this is the sink where that costs
     * the most: an address here outlives the erasure the application confirmed to its subject, in a retention
     * this application does not own. The bag is mutated in place and written back, so the write is explicit
     * rather than a side effect a future reader has to infer from the getter.
     */
    private function redactExceptionValuesOf(Event $event): void
    {
        $exceptions = $event->getExceptions();

        if ([] === $exceptions) {
            return;
        }

        foreach ($exceptions as $exception) {
            $exception->setValue(EmailAddressRedaction::apply($exception->getValue()));
        }

        $event->setExceptions($exceptions);
    }

    /**
     * The template, its parameters and the pre-formatted rendering are three fields of one message, and the
     * SDK's setter takes all three at once — so redacting the template alone would leave the same address in
     * the string a reader actually sees.
     */
    private function redactMessageOf(Event $event): void
    {
        $message = $event->getMessage();

        if (null === $message) {
            return;
        }

        $formatted = $event->getMessageFormatted();

        $event->setMessage(
            EmailAddressRedaction::apply($message),
            \array_map(
                EmailAddressRedaction::apply(...),
                $event->getMessageParams(),
            ),
            null === $formatted ? null : EmailAddressRedaction::apply($formatted),
        );
    }

    /**
     * The caller-controlled half of the event, in the two vocabularies its two shapes need: key names for
     * the structured sub-arrays, then URIs for the fields that are not structured at all.
     *
     * @param array<string, mixed> $request
     *
     * @return array<string, mixed>
     */
    private function scrubRequest(array $request): array
    {
        foreach (self::REQUEST_KEYS as $key) {
            if (isset($request[$key]) && \is_array($request[$key])) {
                $request[$key] = $this->scrub($request[$key]);
            }
        }

        return $this->redactUrisIn($request);
    }

    /**
     * `query_string`, `url` and `Referer` are raw strings in the SDK, so the key-based loop never reaches
     * them — and a denylist is a rule about KEY names, the wrong shape for any of the three. They go through
     * the vocabulary the access log and the per-error log line already share, so an axis redacted in one
     * sink is redacted in all of them. `url` carries the query a second time (the SDK builds it from the
     * whole URI), so redacting only `query_string` would leave the same values on the same event.
     *
     * Sentry is a third-party sink with its own retention that no erasure path can reach, which is what
     * makes an identity axis here outlive the erasure the application confirmed to the subject.
     *
     * @param array<string, mixed> $request
     *
     * @return array<string, mixed>
     */
    private function redactUrisIn(array $request): array
    {
        if (isset($request['query_string']) && \is_string($request['query_string'])) {
            $request['query_string'] = RequestUriRedaction::redactQuery($request['query_string']);
        }

        if (isset($request['url']) && \is_string($request['url'])) {
            $request['url'] = RequestUriRedaction::redact($request['url']);
        }

        if (isset($request['headers']) && \is_array($request['headers'])) {
            $request['headers'] = $this->redactRefererIn($request['headers']);
        }

        return $request;
    }

    /**
     * A denylist is a rule about KEY names, and `extra` holds one field where the identifier is in the VALUE:
     * `sentry/sentry-symfony`'s `ConsoleListener` sets `Full command` to the whole argv line on every console
     * command, and captures it on `ConsoleErrorEvent`. `bin/console mailer:test <address>` and
     * `iam:invitation:create <address>` therefore carry a person's address into a third-party sink with
     * retention of its own, which no erasure path reaches — the same reason `Referer` is redacted above rather
     * than trusted. Dropping the field by key would take the command name with it, and the command name is the
     * only thing that says which control failed.
     *
     * Applied to every string in the sub-tree rather than to that one key, because keying on `Full command`
     * would repeat the mistake this method exists to correct: the next field carrying an address in its value
     * is invisible to a rule that names fields. Over-redacting a diagnostic extra fails in the safe direction.
     *
     * @param array<array-key, mixed> $values
     *
     * @return array<array-key, mixed>
     */
    private function redactAddressesIn(array $values): array
    {
        foreach ($values as $key => $value) {
            if (\is_string($value)) {
                $values[$key] = EmailAddressRedaction::apply($value);

                continue;
            }

            if (\is_array($value)) {
                $values[$key] = $this->redactAddressesIn($value);
            }
        }

        return $values;
    }

    /**
     * `Referer` is the one header whose value IS a URI, and the loop above cannot help it: that scrub matches
     * key NAMES, and the SDK's own sensitive-header list covers credentials only. Left alone it carries the
     * referring document's whole URL — for a call made from the audit screen, the person ids that screen
     * holds — into a third-party sink with retention of its own. Caddy drops the header outright for the same
     * reason; here the value is worth keeping, so it is redacted rather than dropped.
     *
     * @param array<array-key, mixed> $headers
     *
     * @return array<array-key, mixed>
     */
    private function redactRefererIn(array $headers): array
    {
        foreach ($headers as $name => $value) {
            if (\is_string($name) && 'referer' === \strtolower($name) && \is_string($value)) {
                $headers[$name] = RequestUriRedaction::redact($value);
            }
        }

        return $headers;
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
