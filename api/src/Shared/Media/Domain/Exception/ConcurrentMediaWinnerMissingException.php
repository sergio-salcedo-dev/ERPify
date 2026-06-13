<?php

declare(strict_types=1);

namespace Erpify\Shared\Media\Domain\Exception;

use RuntimeException;

/**
 * Raised when the canonical media row that won a concurrent insert cannot be re-fetched by its
 * content hash. Losing the unique-constraint race is expected and recoverable — the registrar
 * falls back to the winning row; the winner then vanishing from the re-query is an unexpected
 * data inconsistency with no safe recovery. As a marker-less {@see RuntimeException} it maps to
 * an `unhandled-exception` 500 and is tagged `runtime_error` for SRE triage.
 */
final class ConcurrentMediaWinnerMissingException extends RuntimeException
{
    public static function forContentHash(string $contentHash): self
    {
        return new self(\sprintf(
            'Media with content hash "%s" won a concurrent insert but cannot be re-fetched.',
            $contentHash,
        ));
    }
}
