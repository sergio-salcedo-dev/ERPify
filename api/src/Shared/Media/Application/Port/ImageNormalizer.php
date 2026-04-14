<?php

declare(strict_types=1);

namespace Erpify\Shared\Media\Application\Port;

use Erpify\Shared\Media\Application\Dto\NormalizedImage;
use Symfony\Component\HttpFoundation\File\UploadedFile;

interface ImageNormalizer
{
    public function normalize(UploadedFile $uploadedFile): NormalizedImage;
}
