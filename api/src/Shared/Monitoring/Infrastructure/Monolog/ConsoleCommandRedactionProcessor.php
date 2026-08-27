<?php

declare(strict_types=1);

namespace Erpify\Shared\Monitoring\Infrastructure\Monolog;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Override;
use Stringable;

/**
 * Reduces the console `command` context key — a failing process's full argv — to the command NAME, whoever
 * wrote the record.
 *
 * Symfony's console `ErrorListener` logs that argv on two paths, both measured against the installed
 * `symfony/console`: `:46` writes `['exception' => …, 'command' => $inputString, 'message' => …]` at CRITICAL
 * on a `ConsoleErrorEvent`, and `:67` writes the same string at DEBUG on ANY non-zero exit
 * (`ConsoleTerminateEvent`). The second is the wider path — it needs no exception at all — and the first is
 * the worse one: prod's `fingers_crossed` handler carries the `console` channel (it excludes only
 * `deprecation` and `observability`) and CRITICAL is above its `action_level: error`, so that record does not
 * merely sit in the buffer, it FLUSHES it to `php://stderr`.
 *
 * **Which invocations actually reach the CRITICAL path is narrower than "a command that fails", and getting
 * this wrong is what an adversarial pass caught.** Every `#[AsCommand]` in this application that takes person
 * data on its command line catches `Throwable` and returns `Command::FAILURE` — the two GDPR subject
 * erasures, the actor-trail erasure, the administrator bootstrap and the three invitation commands, plus the
 * organization provisioner — so none of them can raise a `ConsoleErrorEvent`, and their own failures reach
 * only `:67` at DEBUG. **That membership is a list, and nothing checks it**, which is the same shape this
 * class's own "by structure, never by enumeration" argument refuses one paragraph down: a later command that
 * takes an email or a subject id and lets a throwable escape rejoins the CRITICAL path silently, and an
 * earlier reading of this paragraph named three of the eight without anything going red. The redaction below
 * is what holds regardless; this paragraph only bounds how often it is reached. A DEBUG record buffers without
 * activating, and `FingersCrossedHandler::flushBuffer()` DISCARDS the buffer when no `passthru_level` is
 * configured, which is this deployment. The live producer of `:46` is an invocation the console cannot BIND —
 * an unknown option, a wrong arity, a mistyped command name — because that raises outside the command's own
 * `try`. Its argv is the operator's, and it carries whatever they typed: `identity:gdpr:erase-subject <uuid>
 * --typo` puts a person's identifier into the record of the erasure the command exists to perform, and
 * `organization:administrator:create <email> <password> --typo` puts a password IN CLEAR beside the address
 * it belongs to. The DEBUG path is the second producer and needs no error at all — it reaches the sink
 * whenever something else in the same process activates the buffer.
 *
 * A processor rather than a fix at the listener, for the same reason its two siblings are one: the emitter
 * lives in `vendor/`, where a sweep of `api/src` cannot see it and a patch cannot reach it. Logger-scoped, so
 * it runs ahead of the buffer AND ahead of the handler-scoped `PsrLogMessageProcessor` that interpolates
 * `{command}` into the MESSAGE — which is what makes this the one carrier of the three whose message vector
 * closes with it. {@see PersonDataRedactionProcessor} names that interpolation as the live message-side leak
 * it cannot reach; this class is what reaches it.
 *
 * **By STRUCTURE, never by enumeration, and that is the whole design.** The discarded alternative was a list
 * of sensitive command names whose arguments get redacted while the rest pass intact. It reads as the
 * higher-fidelity option and it is the shape that has already failed twice here: Caddy's access-log `query`
 * filter matched parameter NAMES and let nine spellings of a value through, and the same class of defect is
 * what #389/#803 closed. An enumeration is a floor on accidents and never a ceiling on intent — the command
 * added next is in clear by default, and nothing reds. Keeping the name and dropping everything after it is
 * the same line Caddy's filter now draws at the `?`: the structure decides, so a value nobody anticipated is
 * covered by having been an argument at all.
 *
 * **What the operator keeps** is the only diagnostic this line actually carries — WHICH command failed. The
 * exit code, the exception, and the throwable's own message are untouched siblings, so a flushed buffer still
 * reads. What is spent is argv: an operator can no longer tell from this log which subject
 * `identity:gdpr:erase-subject` was run against. That is deliberate, and `audit_log` is where that question
 * belongs — it carries actor and resource ids and, unlike this sink, HAS an owner of erasure.
 *
 * **The degradation is real and is not hidden.** An invocation LEADING with a global option
 * (`bin/console -v <command>`, `--env=prod <command>`) loses the name too, because locating it past an
 * option would take the command's own input definition — whether `-e` consumes the next token or not — which
 * a processor does not have and must not guess. Measured over this repository, the shape IS invoked, once:
 * `api/frankenphp/docker-entrypoint.sh` runs `php bin/console -V`, the first console call the prod container
 * makes. (`make sf` and both compose `command:` arrays spell `bin/console <command>` first, and
 * `make/php-quality.mk`'s `cache:clear --env=prod` puts its option after the name.) That invocation carries
 * no argument at all, so what the degradation costs there is the string `-V` — and it fails toward a redacted
 * line rather than a leaked one, which is the direction to fail in.
 *
 * **What a green does not cover.** The `message` sibling key, which holds `$throwable->getMessage()` — a
 * throwable composed from a person datum reaches this sink through it, undefended here by construction and
 * by the same argument {@see PersonDataRedactionProcessor} records for `exception`. Nor the ARGV ITSELF
 * outside Monolog: the process list is the other reader of a command line, and no processor can reach it, so
 * a password passed positionally is disclosed to every local process regardless of this class. That residual
 * is recorded in `PRODUCTION_SECURITY_CHECKLIST.md` §7 and is why
 * `organization:administrator:create` keeps its hidden prompt and says so in its own argument description.
 * And the one spelling {@see COMMAND_NAME} still admits: a value glued to the command name using only the
 * characters a command name may contain.
 */
final readonly class ConsoleCommandRedactionProcessor implements ProcessorInterface
{
    /**
     * Spelled here rather than imported from a sibling, which is the convention
     * {@see \Erpify\Shared\ErrorContract\Application\EmailAddressRedaction::SENTINEL} states: each sink names
     * its own token, so the sinks agree on what an operator reads without one depending on another's constant.
     *
     * Sentinel and not strip, for a reason specific to this field: an argv reduced to its command name is
     * indistinguishable from a command that genuinely took no arguments, and those are different facts. The
     * token is what keeps "arguments were here" readable.
     */
    public const string SENTINEL = 'REDACTED';

    /**
     * The key Symfony's console `ErrorListener` writes on both of its paths. Anything else adopting the
     * convention is covered by having done so.
     */
    private const string FIELD = 'command';

    /**
     * Unicode-aware, and the `u` modifier is the whole point. `\s` on its own is the BYTE class
     * `[ \t\n\r\f\v]`, so an argv separated by a non-breaking space (U+00A0), a figure space (U+2007) or an
     * ideographic space (U+3000) parsed as ONE token and was returned verbatim — measured, with a person's id
     * riding in it. `\p{Z}` is the separator category those three belong to.
     */
    private const string SEPARATOR = '/[\s\p{Z}]+/u';

    /**
     * What a Symfony command name may look like here: lowercase colon-separated segments, hyphens and
     * underscores inside a segment. Anything else is operator input wearing the first token's position.
     *
     * **Its residual is declared rather than papered over.** A value glued to the name using only characters
     * this pattern admits — a bare uuid after a `-` or a `:` — is indistinguishable from a longer command
     * name by shape alone, and survives. It cannot carry an address (`@` and `.` are refused) or a password
     * with any symbol in it. Closing that last spelling means matching against the REGISTERED command names
     * rather than a shape, which couples this processor to the console registry; recorded as follow-up
     * rather than done blind.
     */
    private const string COMMAND_NAME = '/^[a-z][a-z0-9_-]*(?::[a-z0-9][a-z0-9_-]*)*$/';

    #[Override]
    public function __invoke(LogRecord $record): LogRecord
    {
        $command = $record->context[self::FIELD] ?? null;

        // Accepted as `Stringable` for the same reason the sibling accepts a `UriInterface`: the formatter
        // stringifies it downstream of every processor, so refusing a non-string here would cover the emitter
        // we measured and let the next one through intact.
        if ($command instanceof Stringable) {
            $command = (string) $command;
        }

        // Fails CLOSED on a value of any other type, which is the direction the sibling argues for and this
        // class originally got wrong: `context['command'] = ['identity:gdpr:erase-subject', '<uuid>']` was
        // returned untouched and `JsonFormatter` emitted the array verbatim. A carrier this rule cannot read
        // is a carrier it cannot vouch for. Only a genuinely absent or empty one is left alone.
        if (null === $command || '' === $command) {
            return $record;
        }

        if (!\is_string($command)) {
            return $record->with(context: [...$record->context, self::FIELD => self::SENTINEL]);
        }

        $redacted = $this->redact($command);

        if ($redacted === $command) {
            return $record;
        }

        $context = $record->context;
        $context[self::FIELD] = $redacted;

        return $record->with(context: $context);
    }

    /**
     * Splitting on whitespace is safe DESPITE `ArgvInput::__toString()` quoting a token that holds a space
     * (`escapeToken` wraps anything outside `[\w-]` in single quotes): a mis-split can only ever produce MORE
     * fragments after the name, and every one of them is discarded. The split is used to find the boundary,
     * never to interpret what is past it.
     */
    private function redact(string $command): string
    {
        $tokens = \preg_split(self::SEPARATOR, \trim($command), -1, PREG_SPLIT_NO_EMPTY);

        // `false` is invalid UTF-8 under the `u` modifier, which is a value nothing here can read — same
        // verdict as any other unreadable carrier.
        if (false === $tokens || [] === $tokens) {
            return self::SENTINEL;
        }

        // A leading option means the name cannot be located without the command's input definition — see the
        // class docblock. Redact whole rather than guess, which is the direction that fails safe.
        if (\str_starts_with($tokens[0], '-')) {
            return self::SENTINEL;
        }

        // The surviving token is kept only if it LOOKS like a command name, and that check is what closes the
        // single-token leak: `identity:gdpr:erase-subject=<uuid>` and
        // `organization:administrator:create:alice@example.test` are each ONE token — the shape an operator
        // produces by typing the separator wrong, and the shape `Application::run()` hands to
        // `ConsoleErrorEvent` on `CommandNotFoundException`, where no command bound and the whole token is
        // operator input. Both used to be returned verbatim at CRITICAL.
        if (1 !== \preg_match(self::COMMAND_NAME, $tokens[0])) {
            return self::SENTINEL;
        }

        // Nothing followed the name, so there is nothing to stand in for. Appending the sentinel here would
        // assert that arguments were hidden when none were passed.
        if (1 === \count($tokens)) {
            return $tokens[0];
        }

        return $tokens[0] . ' ' . self::SENTINEL;
    }
}
