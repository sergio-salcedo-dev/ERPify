<?php

declare(strict_types=1);

namespace Erpify\Shared\Media\Application\Dto;

final readonly class UploadedImage
{
    public function __construct(
        public string $mimeType,
        public string $bytes,
    ) {
    }
}
