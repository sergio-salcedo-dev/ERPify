<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Images\Application;

use Erpify\Shared\Images\Domain\Entity\Image;
use Erpify\Shared\Images\Domain\ImageId;
use Erpify\Shared\Images\Domain\Repository\ImageRepository;
use Erpify\Shared\Persistence\Domain\Exception\ConcurrentUniqueWrite;
use Override;

/**
 * A usable alternative implementation of the lifecycle port, honouring the same contract: identity is
 * unique, `findById()` answers `null` only for a confirmed absence, and `remove()` is idempotent.
 *
 * @internal
 */
final class InMemoryImageRepository implements ImageRepository
{
    /** @var array<string, Image> */
    public array $rows = [];

    #[Override]
    public function save(Image $image): void
    {
        if (isset($this->rows[$image->id()->toString()])) {
            throw ConcurrentUniqueWrite::onWrite('image');
        }

        $this->rows[$image->id()->toString()] = $image;
    }

    #[Override]
    public function remove(Image $image): void
    {
        unset($this->rows[$image->id()->toString()]);
    }

    #[Override]
    public function findById(ImageId $id): ?Image
    {
        return $this->rows[$id->toString()] ?? null;
    }
}
