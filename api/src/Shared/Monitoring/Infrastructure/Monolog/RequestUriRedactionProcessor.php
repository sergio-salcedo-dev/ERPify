<?php

declare(strict_types=1);

namespace Erpify\Shared\Monitoring\Infrastructure\Monolog;

use Erpify\Shared\ErrorContract\Application\RequestUriRedaction;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Override;

/**
 * Applies {@see RequestUriRedaction} to the `request_uri` of every record that carries one, whoever wrote it.
 *
 * The error pipeline redacts the field it builds itself, which closes the emitter it owns and nothing else.
 * The framework has its own: Symfony's `RouterListener` logs `Matched route` at INFO with
 * `request_uri => $request->getUri()`, query string included, on the `request` channel — and in prod that
 * channel sits behind the `fingers_crossed` handler, so any 5xx flushes the preceding buffer, INFO lines and
 * all, to `php://stderr`. That is the Docker json-file driver no compose file gives a rotation, a TTL or an
 * owner of erasure, so a person id that lands there outlives the erasure the application confirmed to the
 * subject.
 *
 * A processor rather than a fix at that listener, because the leak is a property of the FIELD, not of the
 * emitter: `request_uri` is a conventional key any library may write, and the one that shipped it here lives
 * in `vendor/`, where a sweep of `api/src` cannot see it and a patch cannot reach it.
 *
 * **The path is deliberately left intact**, which is the same line {@see RequestUriRedaction} draws for the
 * per-error log line and Caddy's filter draws at the edge: a person id in the path is a declared, accepted
 * residual recorded in `PRODUCTION_SECURITY_CHECKLIST.md`, not an oversight this processor should quietly
 * start closing on one sink out of three. One vocabulary, one residual, three logs that agree.
 */
final readonly class RequestUriRedactionProcessor implements ProcessorInterface
{
    /**
     * The conventional key. Symfony's router listener writes it; so does the error pipeline. Anything else
     * that adopts the convention is covered by having done so.
     */
    private const string FIELD = 'request_uri';

    #[Override]
    public function __invoke(LogRecord $record): LogRecord
    {
        $requestUri = $record->context[self::FIELD] ?? null;

        if (!\is_string($requestUri)) {
            return $record;
        }

        $context = $record->context;
        $context[self::FIELD] = RequestUriRedaction::redact($requestUri);

        return $record->with(context: $context);
    }
}
