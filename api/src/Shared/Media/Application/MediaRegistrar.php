<?php

declare(strict_types=1);

namespace Erpify\Shared\Media\Application;

use Erpify\Shared\Media\Application\Port\ImageNormalizer;
use Erpify\Shared\Media\Domain\Entity\Media;
use Erpify\Shared\Media\Domain\Repository\MediaRepository;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

final class MediaRegistrar
{
    public function __construct(
        private readonly ImageNormalizer $normalizer,
        private readonly MediaRepository $repository,
    ) {
    }

    public function registerFromUploadedFile(UploadedFile $file): Media
    {
        $normalized = $this->normalizer->normalize($file);

        $existing = $this->repository->findActiveByContentHash($normalized->contentHash);
        if ($existing !== null) {
            return $existing;
        }

        $media = Media::create(
            Uuid::v4(),
            $normalized->contentHash,
            $normalized->mimeType,
            \strlen($normalized->bytes),
            $normalized->bytes,
        );

        $this->repository->save($media);

        return $media;
    }
}
