<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Media\Infrastructure\Persistence\Doctrine;

use Erpify\Shared\Media\Domain\Entity\Media;
use Erpify\Shared\Media\Domain\Repository\MediaRepository;
use Override;

/**
 * Finder {@see MediaRepository} double for the resolver: `findByContentHash()` returns `$winner`
 * only from the `$winnerVisibleFromFind`-th call onward (1 = immediately), modelling the READ
 * COMMITTED window where the winning insert is not yet visible to the first post-reset re-query.
 * The write/exists methods are unused by the resolver under test.
 *
 * @internal
 */
final class RefetchingMediaRepository implements MediaRepository
{
    public int $findCalls = 0;

    public function __construct(
        private readonly ?Media $winner,
        private readonly int $winnerVisibleFromFind = 1,
    ) {
    }

    #[Override]
    public function saveOrGetByContentHash(Media $media): Media
    {
        return $media;
    }

    #[Override]
    public function findByContentHash(string $contentHash): ?Media
    {
        ++$this->findCalls;

        return $this->findCalls >= $this->winnerVisibleFromFind ? $this->winner : null;
    }

    #[Override]
    public function existsByContentHash(string $contentHash): bool
    {
        return false;
    }
}
