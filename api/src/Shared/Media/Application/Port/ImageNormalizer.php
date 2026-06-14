<?php

declare(strict_types=1);

namespace Erpify\Shared\Media\Application\Port;

use Erpify\Shared\Media\Application\Dto\NormalizedImage;
use Erpify\Shared\Media\Application\Dto\UploadedImage;

interface ImageNormalizer
{
    public function normalize(UploadedImage $uploadedImage): NormalizedImage;
}
