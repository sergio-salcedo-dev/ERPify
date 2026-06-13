<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Media\Application;

use Erpify\Shared\Media\Domain\Entity\Media;
use Erpify\Shared\Media\Domain\Repository\MediaRepository;
use Override;

/**
 * Recording {@see MediaRepository} that counts calls so a test can pin
 * {@see \Erpify\Shared\Media\Application\MediaRegistrar}'s orchestration. The dedup lookup returns
 * `$found`; `saveOrGetByContentHash()` returns `$winner` when set (a concurrent insert won the race)
 * or the media it was handed otherwise (a clean insert).
 *
 * @internal
 */
final class RecordingMediaRepository implements MediaRepository
{
    public int $saveOrGetCalls = 0;

    public int $findCalls = 0;

    public function __construct(
        private readonly ?Media $found = null,
        private readonly ?Media $winner = null,
    ) {
    }

    #[Override]
    public function saveOrGetByContentHash(Media $media): Media
    {
        ++$this->saveOrGetCalls;

        return $this->winner ?? $media;
    }

    #[Override]
    public function findByContentHash(string $contentHash): ?Media
    {
        ++$this->findCalls;

        return $this->found;
    }

    #[Override]
    public function existsByContentHash(string $contentHash): bool
    {
        return $this->found instanceof Media;
    }
}
