<?php

declare(strict_types=1);

namespace Erpify\Shared\Media\Application;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Persistence\ManagerRegistry;
use Erpify\Shared\Application\Validation\Validator;
use Erpify\Shared\Domain\Uuid\Uuid;
use Erpify\Shared\Media\Application\Port\ImageNormalizer;
use Erpify\Shared\Media\Domain\Entity\Media;
use Erpify\Shared\Media\Domain\Repository\MediaRepository;
use RuntimeException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final readonly class MediaRegistrar
{
    public function __construct(
        private ImageNormalizer $imageNormalizer,
        private ManagerRegistry $managerRegistry,
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

        try {
            $this->mediaRepository->save($media);
        } catch (UniqueConstraintViolationException) {
            // why: a concurrent request inserted the same content hash between the dedup
            // lookup above and this flush; media_content_hash_uniq rejected ours, so the
            // winning row is the canonical one — fall back to it.
            return $this->concurrentWinner($normalizedImage->contentHash);
        }

        return $media;
    }

    private function concurrentWinner(string $contentHash): Media
    {
        // The failed flush closed the entity manager; reset it so this re-query (and any
        // persistence work the caller does afterwards) runs on a fresh, open one.
        $this->managerRegistry->resetManager();

        $winner = $this->mediaRepository->findByContentHash($contentHash);

        if (!$winner instanceof Media) {
            throw new RuntimeException(\sprintf(
                'Media with content hash "%s" won a concurrent insert but cannot be re-fetched.',
                $contentHash,
            ));
        }

        return $winner;
    }
}
