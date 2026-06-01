<?php

declare(strict_types=1);

namespace Erpify\Shared\Storage\Domain;

/**
 * Immutable handle to a content-addressable blob persisted by
 * {@see \Erpify\Shared\Storage\Application\StoredImageObjectWriter}.
 * Groups the four columns (key, mime type, byte size, content hash) that
 * aggregates such as Bank persist alongside their row.
 */
final readonly class StoredObject
{
    public function __construct(
        public string $objectKey,
        public string $mimeType,
        public int $byteSize,
        public string $contentHash,
    ) {
    }
}
