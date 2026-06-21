<?php

declare(strict_types=1);

namespace Erpify\Shared\Search\Domain\Exception;

use Erpify\Shared\Domain\Exception\DomainException;
use Erpify\Shared\Domain\Exception\InvalidSearchCriteria;
use Throwable;

/**
 * Thrown when a pagination cursor fails validation. Implements the existing
 * {@see InvalidSearchCriteria} marker, so the Problem Details pipeline maps it
 * to HTTP 422 with no extra wiring — the same family as the other
 * search-criteria violations (it is well-formed-but-unprocessable query input).
 *
 * The four named constructors mirror the cursor-validation DAG order
 * (signature → version → payload → fingerprint). The cause is distinguishable
 * INTERNALLY ({@see InvalidCursor::$cause}, for logs and the
 * `invalid_cursor_count{cause}` metric of PR3) but the wire response is
 * deliberately INDISTINGUISHABLE across causes: identical `type`
 * (`invalid-cursor`), identical title, empty `context`. A raw cursor is never
 * placed in the message, the context, or the logs — only the cause travels.
 */
final class InvalidCursor extends DomainException implements InvalidSearchCriteria
{
    public const string TYPE = 'invalid-cursor';

    private const string TITLE = 'The pagination cursor is invalid.';

    private function __construct(public readonly InvalidCursorCause $cause, ?Throwable $previous = null)
    {
        parent::__construct(type: self::TYPE, title: self::TITLE, context: [], previous: $previous);
    }

    public static function signature(?Throwable $previous = null): self
    {
        return new self(InvalidCursorCause::Signature, $previous);
    }

    public static function version(?Throwable $previous = null): self
    {
        return new self(InvalidCursorCause::Version, $previous);
    }

    public static function payload(?Throwable $previous = null): self
    {
        return new self(InvalidCursorCause::Payload, $previous);
    }

    public static function fingerprint(?Throwable $previous = null): self
    {
        return new self(InvalidCursorCause::Fingerprint, $previous);
    }
}
