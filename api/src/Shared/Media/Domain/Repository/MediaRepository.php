<?php

declare(strict_types=1);

namespace Erpify\Shared\Media\Domain\Repository;

use Erpify\Shared\Media\Domain\Entity\Media;

interface MediaRepository
{
    public function persist(Media $media): void;

    public function findActiveByContentHash(string $contentHash): ?Media;

    public function existsActiveByContentHash(string $contentHash): bool;
}
