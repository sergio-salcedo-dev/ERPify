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
 * Two argv shapes in this application carry what must not reach that sink. `identity:gdpr:erase-subject
 * <uuid>` writes a person's identifier into a record of the erasure the command exists to perform — the
 * subject is told they were erased and the identifier survives the telling. `organization:administrator:create
 * <email> <password>` writes a password IN CLEAR beside the address it belongs to. `iam:invitation:create`
 * takes an email too.
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
 * a processor does not have and must not guess. Measured over this repository, nothing invokes that shape:
 * `make sf`, the two compose `command:` arrays and `docker-entrypoint.sh` all spell `bin/console <command>`
 * first. So the cost is a diagnostic in a shape nobody runs, against a rule that needs no knowledge of the
 * console at all — and the day someone runs it, they get a redacted line rather than a leaked one, which is
 * the direction to fail in.
 *
 * **What a green does not cover.** The `message` sibling key, which holds `$throwable->getMessage()` — a
 * throwable composed from a person datum reaches this sink through it, undefended here by construction and
 * by the same argument {@see PersonDataRedactionProcessor} records for `exception`. Nor the ARGV ITSELF
 * outside Monolog: the process list is the other reader of a command line, and no processor can reach it, so
 * a password passed positionally is disclosed to every local process regardless of this class. That residual
 * is recorded in `PRODUCTION_SECURITY_CHECKLIST.md` §7 and is why
 * `organization:administrator:create` keeps its hidden prompt and says so in its own argument description.
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

        if (!\is_string($command) || '' === $command) {
            return $record;
        }

        $redacted = self::redact($command);

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
    private static function redact(string $command): string
    {
        $tokens = \preg_split('/\s+/', \trim($command), -1, \PREG_SPLIT_NO_EMPTY);

        if (false === $tokens || [] === $tokens) {
            return self::SENTINEL;
        }

        // A leading option means the name cannot be located without the command's input definition — see the
        // class docblock. Redact whole rather than guess, which is the direction that fails safe.
        if (\str_starts_with($tokens[0], '-')) {
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
