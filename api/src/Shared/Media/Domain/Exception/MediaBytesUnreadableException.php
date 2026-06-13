<?php

declare(strict_types=1);

namespace Erpify\Shared\Media\Domain\Exception;

use RuntimeException;

/**
 * Raised when a media row's stored BLOB stream cannot be read into bytes. A failed
 * {@see \stream_get_contents} read is an environmental I/O fault, not a client or business
 * error: callers serve these bytes as the HTTP response body, so degrading to an empty string
 * would be silent, cacheable corruption. As a marker-less {@see RuntimeException} it maps to an
 * `unhandled-exception` 500 and is tagged `runtime_error` for SRE triage.
 */
final class MediaBytesUnreadableException extends RuntimeException
{
    public static function forMediaId(string $mediaId): self
    {
        return new self(\sprintf('Failed to read raw bytes stream of media "%s".', $mediaId));
    }
}
