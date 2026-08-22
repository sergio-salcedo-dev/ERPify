<?php

declare(strict_types=1);

namespace Erpify\Shared\Monitoring\Infrastructure\Monolog;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Override;

/**
 * Replaces the value of the four context keys the security stack names a person in with {@see SENTINEL},
 * whoever wrote the record.
 *
 * That stack names a person on every authenticated request. Symfony's `ContextListener` logs
 * `['username' => $refreshedUser->getUserIdentifier()]` at DEBUG, and here that identifier is the person's
 * email — `SecurityUser` in the Identity context answers `getUserIdentifier()` with `$this->user->email()`; on
 * login `AuthenticatorManager` logs the token OBJECT, whose `__toString()` spells the same address out again.
 * In prod both channels sit behind the `fingers_crossed` handler at `level: debug`, so any 5xx flushes them —
 * about seven records into a fifty-record buffer — to `php://stderr`, the Docker json-file driver no compose
 * files bound by size alone, with no TTL and no owner of erasure. That address outlives the erasure the application
 * confirmed to the subject.
 *
 * A processor rather than a fix at the emitters, for the same reason {@see RequestUriRedactionProcessor} is
 * one: every emitter lives in `vendor/`, where a sweep of `api/src` cannot see it and a patch cannot reach it.
 *
 * **The value is replaced whole, not parsed.** `token` arrives as a `TokenInterface`, and PHP 8 makes any
 * class declaring `__toString()` a `Stringable`, so `JsonFormatter` — prod's — spells the identifier out
 * DOWNSTREAM of every processor. Redacting the value regardless of its type is what closes that: a rule that
 * inspected strings alone would cover `username` and let the object through to the formatter intact.
 *
 * **Keys are matched byte for byte**, like the sibling's, and not case-folded: measured, every deployed
 * emitter spells all four in lower case, so a fold would buy a spelling nobody writes at the price of a
 * `strtolower()` on the debug-level hot path. A library that spells one differently is a carrier to add here,
 * exactly like any other.
 *
 * **By carrier and not by value pattern — and the reason is NOT cost.** Measured on this stack over a mix of
 * the record shapes below, this loop costs 0.050 µs against 0.097 µs for an
 * {@see \Erpify\Shared\ErrorContract\Application\EmailAddressRedaction} pass over the top-level string values;
 * both are tens of nanoseconds and which one wins moves with the mix, so an argument from the hot path would
 * prove nothing in either direction. Three real reasons. That class bounds itself to "a message THIS
 * APPLICATION composed", and a logger processor sees every record on every channel, composed overwhelmingly
 * by `vendor/` — the general-purpose use it declines. It would reach the console `command` argv while
 * redacting only the address in it and leaving the plaintext password beside it, which retires that issue's
 * appearance and not its worse half. And it would not reach the nested `params` of a Doctrine statement, so
 * it buys the look of coverage over the one residual below that is actually a value leak.
 * {@see \Erpify\Shared\Monitoring\Infrastructure\Sentry\SentryEventScrubber} reaches the opposite verdict
 * on purpose: it walks a bounded sub-tree of an event captured once per error, not every record of every
 * channel.
 *
 * **What is deliberately NOT here.**
 *
 *  - `route_parameters`. On `GET /api/v1/backoffice/users/<uuid>` the router listener writes it and
 *    `request_uri` in the SAME record, holding the same uuid, because a route parameter is a path segment.
 *    The path of `request_uri` is a declared, accepted residual (`PRODUCTION_SECURITY_CHECKLIST.md`) held in
 *    common with Caddy's access log, which filters `request>uri query` and cannot touch a path at all.
 *    Redacting one spelling while the other stands in the same record removes nothing and costs the operator
 *    the parameters. Bounded rather than absolute, and `DeclinedRouteParameterCarrierTest` owns the whole
 *    argument: it pins the direction that can be pinned (the sibling starts redacting paths) and states the
 *    one that cannot (a route comes to carry a person-valued placeholder whose decoded and encoded forms
 *    differ, at which point `route_parameters` is the only spelling and nothing reds).
 *  - `command`, the console `ErrorListener`'s full argv of a failing process — a distinct disclosure with a
 *    distinct fix, and it HAS one now: {@see ConsoleCommandRedactionProcessor} keeps the command name and
 *    drops the rest. A key here would have half-closed it, redacting the address in that argv and leaving
 *    the plaintext password beside it.
 *  - `given`, which `UrlGenerator` logs at ERROR on the `router` channel when a generated parameter fails its
 *    route requirement. No route that can carry a person's id declares one — the two that declare
 *    `requirements` at all are in `config/routes/dev.yaml` and `config/routes/test.yaml`, over a profiler
 *    token — and the default `[^/]++` admits every uuid, so the carrier has no producer holding a person
 *    datum. The value IS that record's whole diagnostic. It becomes a carrier the day a person-addressed
 *    route declares a requirement, and it is named here so that day is not silent.
 *
 * **What a green does not cover.** The record's MESSAGE, which this never reads, and the message is NOT
 * quiet — reading it as "no `{carrier}` placeholder exists" is the mistake to avoid. MonologBundle pushes
 * `PsrLogMessageProcessor` onto a non-wrapping HANDLER (prod's `nested`, not the `fingers_crossed` `main`),
 * so it interpolates DOWNSTREAM of every logger processor. What it interpolates today is `{command}`, and
 * that slot is no longer a leak: {@see ConsoleCommandRedactionProcessor} is logger-scoped, so the value is
 * already reduced to the command name by the time this runs. Other identifiers are
 * `sprintf`-composed into a message before Monolog exists at all: HttpKernel's `ErrorListener` writes
 * `Uncaught PHP Exception …: "<message>"`, and `CurlHttpClient` writes `Request: "%s %s"` on the buffered
 * `http_client` channel. That first one does not fire on `/api/*` — `ExceptionResponder` sets a response at
 * priority 16 and `ExceptionEvent` inherits `RequestEvent::setResponse()`, which stops propagation ahead of
 * the listener's priority 0 — so its live population is routing failures outside `/api/`. Related, and
 * undefended by construction:
 * `context['exception']` is preserved deliberately, since `HttpCodeActivationStrategy` reads it to decide
 * which statuses flush at all, and the formatter writes that throwable's own `getMessage()` and recurses into
 * `previous`. No live path carries an identifier there — this application's `UserNotFoundException` is thrown
 * message-less and its user repository converts a unique-violation without chaining the driver exception —
 * but that is discipline, not a gate. Nor does this reach a carrier NESTED inside another value, or one riding
 * in `extra` — the compiled prod container holds four processors in the whole logging graph, this one, its
 * two siblings and `PsrLogMessageProcessor`, and none writes `extra`. The class that would is Symfony's
 * `AbstractTokenProcessor`, which puts `user_identifier` under `extra.token`; enrolling it reopens this on a
 * key nothing here reads.
 *
 * **And a processor enrolled AHEAD of this one sees the record unredacted.** `Logger::pushProcessor` is an
 * `array_unshift`, so the last one pushed runs first — which under dev/test is Symfony's `DebugProcessor`,
 * pushed after both redaction processors by `pushDebugLogger()` and persisting the raw record into the
 * profiler under `var/cache/dev`. That is outside the deployed sink this class is about and is not defended
 * here; the enrolment test asserts the rule is PRESENT on every channel logger, never that it is first.
 *
 * And the carrier SET is grounded only in the direction of decay. `PersonDataCarrierEmitterGateTest` reds
 * when a named emitter stops spelling a carrier; nothing reds when the dependency starts writing a NEW one, so
 * a `symfony/security-*` bump is read by a person or not at all.
 */
final readonly class PersonDataRedactionProcessor implements ProcessorInterface
{
    /**
     * Spelled here rather than imported from a sibling vocabulary, which is the convention
     * {@see \Erpify\Shared\ErrorContract\Application\EmailAddressRedaction::SENTINEL} states: each sink names
     * its own token so the sinks agree on what an operator reads without one depending on another's constant.
     *
     * Sentinel and not strip — the opposite of
     * {@see \Erpify\Shared\ErrorContract\Application\RedactionDenylist}, which removes the key, because the
     * sinks differ: there the reader is a caller who learns something from the presence of a field named
     * `password`, here it is an operator who reads a missing identity as a bug in the firewall.
     *
     * A CONSTANT, and not a stable pseudonym or the person's id, which would keep records of one subject
     * correlatable. A pseudonym derived from the address is a person datum unless it is keyed and the key is
     * erasable, which is a keystore this sink has no claim on; the id is worse, because writing it here mints
     * a new person reference in a sink with a size bound but no TTL and no owner of erasure — the exact shape
     * `api/.person-reference-policy` exists to refuse. Correlation is served by `audit_log`, which carries
     * actor ids and HAS an erasure owner, so the diagnostic is not lost, only moved to the sink that can
     * answer for it.
     */
    public const string SENTINEL = 'REDACTED';

    /**
     *  - `username` — `getUserIdentifier()`, five emitters in `symfony/security-http` alone (`ContextListener`
     *    on reload, on a changed user and on an unknown one; `SwitchUserListener`; `HttpBasicAuthenticator`).
     *  - `impersonator_username` — the same call on a `SwitchUserToken`, in the same context array
     *    `ContextListener` builds the measured one in. `switch_user` is not configured today, so it has no
     *    producer; it is listed because the only thing between it and this sink is a firewall option, and a
     *    carrier set that has to be revisited the day someone turns that option on is a set that is wrong
     *    exactly when it matters.
     *  - `token` — the authenticated `TokenInterface` `AuthenticatorManager` logs on a successful credential
     *    exchange, which stringifies to `…(user="<address>", roles=…)`.
     *  - `received` — what `ContextListener` calls a session token it could not use, on two branches that
     *    carry different things. One is the raw SERIALIZED token, which holds the address and the password
     *    hash together; the other is whatever the session deserialised to when that was NOT a
     *    `TokenInterface`, so it can be any value at all — redacting it costs the operator that value while
     *    leaving the message ("got something else") intact, which is the trade taken because a value the
     *    firewall could not classify is exactly the one nothing can promise is impersonal. Both at WARNING,
     *    which the buffer holds like any other record.
     *
     * Read by reflection twice, because the list fails in two independent directions.
     * `PersonDataRedactionProcessorTest` holds it against the cases that exercise it, so a carrier added here
     * without a case fails the build instead of sitting behind a green one; `PersonDataCarrierEmitterGateTest`
     * holds it against the installed classes that spell it, so a carrier the dependency stopped writing fails
     * the build instead of guarding a key nobody produces.
     */
    private const array CARRIERS = ['username', 'impersonator_username', 'token', 'received'];

    #[Override]
    public function __invoke(LogRecord $record): LogRecord
    {
        $context = $record->context;
        $redacted = false;

        // Probed by key rather than by walking the context: every record on every channel reaches this, and
        // the overwhelming majority carry no carrier at all, so the common case costs four lookups and no
        // allocation. Walking would allocate a key list per record for the same answer.
        //
        // A null value is left alone, and the distinction is the operator's not ours: `HttpBasicAuthenticator`
        // logs `username => $request->headers->get('PHP_AUTH_USER')`, which is null when the request carried
        // no user segment at all. Stamping the sentinel over that answers "an identifier was here and we hid
        // it" where the diagnostic is "none was sent", and it hides nothing, since null is not a person.
        foreach (self::CARRIERS as $carrier) {
            if (null === ($context[$carrier] ?? null)) {
                continue;
            }

            $context[$carrier] = self::SENTINEL;
            $redacted = true;
        }

        return $redacted ? $record->with(context: $context) : $record;
    }
}
