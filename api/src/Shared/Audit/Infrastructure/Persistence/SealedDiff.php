<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Infrastructure\Persistence;

/**
 * The outcome of sealing a change diff: the metadata to persist (with any personal-data field encrypted)
 * and the encryption scope the row references, or null when nothing was personal and the diff stays clear.
 */
final readonly class SealedDiff
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public array $metadata,
        public ?string $encryptionScopeId,
    ) {
    }
}
