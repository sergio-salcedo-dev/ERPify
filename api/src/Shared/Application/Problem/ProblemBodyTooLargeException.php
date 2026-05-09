<?php

declare(strict_types=1);

namespace Erpify\Shared\Application\Problem;

use RuntimeException;

/**
 * Escalation marker thrown by {@see ProblemDetailsFactory} when the required core fields
 * (`type`, `title`, `status`, `instance`, `correlation-id`) — together with the lone
 * `'truncated' => true` extension marker — STILL exceed the 16 KiB body cap after every
 * other extension has been dropped.
 *
 * The factory deliberately throws on this branch instead of emitting a partial body so
 * {@see \Erpify\Shared\Infrastructure\Http\EventListener\ExceptionResponder}'s outer
 * `try { ... } catch (\Throwable) { ... }` catches it and escalates to the byte-for-byte
 * literal last-resort static body. The static body is self-sufficient (omits `instance`,
 * `correlation-id`, and any `extensions`), so it is the ONLY response shape guaranteed to
 * fit when the per-error correlation pair plus the type/title/status header already
 * overflow the cap.
 *
 * This is an exotic path: a 16 KiB overflow on the required core fields requires a multi-KB
 * `title` (every other core field is fixed-width: `type` is short, `status` is 3 digits,
 * `instance` and `correlation-id` are RFC 4122 36-character strings). It exists to keep the
 * cap contract a strict invariant rather than a best-effort guideline.
 */
final class ProblemBodyTooLargeException extends RuntimeException
{
}
