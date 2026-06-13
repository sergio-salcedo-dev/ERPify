<?php

declare(strict_types=1);

namespace Erpify\Shared\Media\Application;

use Erpify\Shared\Application\Validation\Validator;
use Erpify\Shared\Domain\Uuid\Uuid;
use Erpify\Shared\Media\Application\Port\ImageNormalizer;
use Erpify\Shared\Media\Domain\Entity\Media;
use Erpify\Shared\Media\Domain\Repository\MediaRepository;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final readonly class MediaRegistrar
{
    public function __construct(
        private ImageNormalizer $imageNormalizer,
        private MediaRepository $mediaRepository,
        private Validator $validator,
    ) {
    }

    public function registerFromUploadedFile(UploadedFile $uploadedFile): Media
    {
        $normalizedImage = $this->imageNormalizer->normalize($uploadedFile);

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
        );

        $this->validator->ensure($media);

        return $this->mediaRepository->saveOrGetByContentHash($media);
    }
}
