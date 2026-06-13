<?php

declare(strict_types=1);

namespace Erpify\Shared\Media\Domain\Repository;

use Erpify\Shared\Media\Domain\Entity\Media;
use Erpify\Shared\Media\Domain\Exception\ConcurrentMediaWinnerMissingException;

interface MediaRepository
{
    /**
     * Persists the media, or returns the canonical winning row when a concurrent request already
     * registered the same content hash and the unique index rejects this insert.
     *
     * @throws ConcurrentMediaWinnerMissingException when the winner cannot be re-fetched
     */
    public function saveOrGetByContentHash(Media $media): Media;

    public function findByContentHash(string $contentHash): ?Media;

    public function existsByContentHash(string $contentHash): bool;
}
