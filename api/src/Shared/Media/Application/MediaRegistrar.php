<?php

declare(strict_types=1);

namespace Erpify\Shared\Media\Application;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Persistence\ManagerRegistry;
use Erpify\Shared\Application\Validation\Validator;
use Erpify\Shared\Domain\Uuid\Uuid;
use Erpify\Shared\Media\Application\Port\ImageNormalizer;
use Erpify\Shared\Media\Domain\Entity\Media;
use Erpify\Shared\Media\Domain\Exception\ConcurrentMediaWinnerMissingException;
use Erpify\Shared\Media\Domain\Repository\MediaRepository;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final readonly class MediaRegistrar
{
    /**
     * Re-fetch attempts for the concurrent winner. Under READ COMMITTED the winning insert's row
     * may not be visible at the first re-query (its transaction can commit just after ours rolled
     * back on the unique violation); a second attempt distinguishes "not yet visible" from
     * "genuinely absent" before escalating to an unrecoverable 500.
     */
    private const int WINNER_REFETCH_ATTEMPTS = 2;

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
        // The failed flush closed the entity manager; reset it so each re-query (and any
        // persistence work the caller does afterwards) runs on a fresh, open one. Symfony
        // re-initialises the EM proxy in place, so the injected repository transparently
        // binds to the fresh manager. A bounded retry absorbs the read-committed visibility
        // gap (see WINNER_REFETCH_ATTEMPTS) before we treat the winner as truly missing.
        for ($attempt = 0; $attempt < self::WINNER_REFETCH_ATTEMPTS; ++$attempt) {
            $this->managerRegistry->resetManager();

            $winner = $this->mediaRepository->findByContentHash($contentHash);

            if ($winner instanceof Media) {
                return $winner;
            }
        }

        throw ConcurrentMediaWinnerMissingException::forContentHash($contentHash);
    }
}
