<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Media\Application;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Erpify\Shared\Media\Domain\Entity\Media;
use Erpify\Shared\Media\Domain\Repository\MediaRepository;
use Override;

/**
 * In-memory {@see MediaRepository} that records call counts, so a test can pin
 * {@see \Erpify\Shared\Media\Application\MediaRegistrar}'s dedup-and-concurrent-insert flow.
 *
 * When `$saveFailure` is given, `save()` throws it instead of completing — mimicking the
 * `media_content_hash_uniq` index rejecting a row another request inserted first.
 * `findByContentHash()` always returns `$found`, so passing `null` reproduces both the initial
 * dedup miss and the winner-vanished re-fetch in a single fixture.
 *
 * @internal
 */
final class FakeMediaRepository implements MediaRepository
{
    public int $saveCalls = 0;

    public int $findCalls = 0;

    public function __construct(
        private readonly ?Media $found = null,
        private readonly ?UniqueConstraintViolationException $saveFailure = null,
    ) {
    }

    #[Override]
    public function save(Media $media): void
    {
        ++$this->saveCalls;

        if ($this->saveFailure instanceof UniqueConstraintViolationException) {
            throw $this->saveFailure;
        }
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
