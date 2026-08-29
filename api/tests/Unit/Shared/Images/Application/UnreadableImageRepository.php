<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Images\Application;

use Erpify\Shared\Images\Domain\Entity\Image;
use Erpify\Shared\Images\Domain\ImageId;
use Erpify\Shared\Images\Domain\Repository\ImageRepository;
use Override;

/**
 * A repository whose lookup fails, so a test can distinguish "the row is not there" from "I could not
 * read it" — the two the deletion path must never conflate.
 *
 * @internal
 */
final class UnreadableImageRepository implements ImageRepository
{
    #[Override]
    public function save(Image $image): void
    {
    }

    #[Override]
    public function remove(Image $image): void
    {
    }

    #[Override]
    public function findById(ImageId $id): ?Image
    {
        throw new StubPersistenceFailure('the row could not be read');
    }
}
