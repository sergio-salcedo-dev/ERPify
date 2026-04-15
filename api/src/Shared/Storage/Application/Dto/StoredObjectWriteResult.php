<?php

declare(strict_types=1);

namespace Erpify\Shared\Storage\Application\Dto;

final readonly class StoredObjectWriteResult
{
    public function __construct(
        public string $objectKey,
        public string $mimeType,
        public int $byteSize,
        public string $contentHash,
    ) {
    }
}
