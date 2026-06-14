<?php

declare(strict_types=1);

namespace Erpify\Shared\Media\Application;

use Erpify\Shared\Application\Validation\Validator;
use Erpify\Shared\Domain\Clock\Clock;
use Erpify\Shared\Domain\Uuid\Uuid;
use Erpify\Shared\Media\Application\Dto\UploadedImage;
use Erpify\Shared\Media\Application\Port\ImageNormalizer;
use Erpify\Shared\Media\Domain\Entity\Media;
use Erpify\Shared\Media\Domain\Repository\MediaRepository;

final readonly class MediaRegistrar
{
    public function __construct(
        private ImageNormalizer $imageNormalizer,
        private MediaRepository $mediaRepository,
        private Validator $validator,
        private Clock $clock,
    ) {
    }

    public function register(UploadedImage $uploadedImage): Media
    {
        $normalizedImage = $this->imageNormalizer->normalize($uploadedImage);

        $existing = $this->mediaRepository->findByContentHash($normalizedImage->contentHash);

        if ($existing instanceof Media) {
            return $existing;
        }

        $media = Media::create(
            Uuid::generate(),
            $normalizedImage->contentHash,
            $normalizedImage->mimeType,
            \strlen($normalizedImage->bytes),
            $normalizedImage->bytes,
            $this->clock->now(),
        );

        $this->validator->ensure($media);

        return $this->mediaRepository->saveOrGetByContentHash($media);
    }
}
