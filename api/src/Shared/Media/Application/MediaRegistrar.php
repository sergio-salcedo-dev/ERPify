<?php

declare(strict_types=1);

namespace Erpify\Shared\Media\Application;

use Erpify\Shared\Media\Application\Port\ImageNormalizer;
use Erpify\Shared\Media\Domain\Entity\Media;
use Erpify\Shared\Media\Domain\Repository\MediaRepository;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

final readonly class MediaRegistrar
{
    public function __construct(
        private ImageNormalizer $imageNormalizer,
        private MediaRepository $mediaRepository,
    ) {}

    public function registerFromUploadedFile(UploadedFile $uploadedFile): Media
    {
        $normalizedImage = $this->imageNormalizer->normalize($uploadedFile);

        $existing = $this->mediaRepository->findActiveByContentHash($normalizedImage->contentHash);

        if ($existing instanceof Media) {
            return $existing;
        }

        $media = Media::create(
            Uuid::v4(),
            $normalizedImage->contentHash,
            $normalizedImage->mimeType,
            \strlen($normalizedImage->bytes),
            $normalizedImage->bytes,
        );

        $this->mediaRepository->save($media);

        return $media;
    }
}
