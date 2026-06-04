<?php

declare(strict_types=1);

namespace Erpify\Shared\Media\Domain\Repository;

use Erpify\Shared\Media\Domain\Entity\Media;

interface MediaRepository
{
    public function save(Media $media): void;

    public function findByContentHash(string $contentHash): ?Media;

    public function existsByContentHash(string $contentHash): bool;
}
