<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Images\Application;

use Erpify\Shared\Images\Domain\Entity\Image;
use Erpify\Shared\Images\Domain\ImageId;
use Erpify\Shared\Images\Domain\Repository\ImageRepository;
use Override;
use RuntimeException;

/**
 * A repository whose write always fails, so a test can observe what the use case leaves behind when the
 * row cannot be written after the bytes already have been.
 *
 * @internal
 */
final class FailingImageRepository implements ImageRepository
{
    #[Override]
    public function save(Image $image): void
    {
        throw new RuntimeException('the row could not be written');
    }

    #[Override]
    public function remove(Image $image): void
    {
    }

    #[Override]
    public function findById(ImageId $id): ?Image
    {
        return null;
    }
}
