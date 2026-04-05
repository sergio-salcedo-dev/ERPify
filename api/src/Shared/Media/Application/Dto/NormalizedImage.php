<?php

declare(strict_types=1);

namespace Erpify\Shared\Media\Application\Dto;

final readonly class NormalizedImage
{
    public function __construct(
        public string $bytes,
        public string $mimeType,
        public string $contentHash,
    ) {
    }
}
