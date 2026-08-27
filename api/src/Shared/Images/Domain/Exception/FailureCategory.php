<?php

declare(strict_types=1);

namespace Erpify\Shared\Images\Domain\Exception;

/**
 * The closed vocabulary NFR9's observability signal reports a failure under — fixed here so the
 * value is never an invented-on-the-spot string at a log call site. Every {@see ImageProcessingException}
 * maps to exactly one of these.
 */
enum FailureCategory: string
{
    case EmptyInput = 'empty_input';
    case InputTooLarge = 'input_too_large';
    case UnsupportedFormat = 'unsupported_format';
    case MimeMismatch = 'mime_mismatch';
    case ResourceLimitExceeded = 'resource_limit_exceeded';
    case DecodeFailure = 'decode_failure';
    case ProcessingFailure = 'processing_failure';
}
