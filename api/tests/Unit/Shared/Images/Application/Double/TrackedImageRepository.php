<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Images\Application\Double;

use Erpify\Shared\Images\Domain\DerivativeOrigin;
use Erpify\Shared\Images\Domain\Entity\Image;
use Erpify\Shared\Images\Domain\ImageId;
use Erpify\Shared\Images\Domain\Repository\ImageRepository;
use Erpify\Tests\Unit\Shared\Images\Application\InMemoryImageRepository;
use Override;

/**
 * {@see InMemoryImageRepository} that notes which unit of work each save ran in, so a case can show the image row is
 * written by the upload unit and never inside the owner's.
 *
 * @internal
 */
final readonly class TrackedImageRepository implements ImageRepository
{
    public function __construct(
        public InMemoryImageRepository $rows,
        private RollbackingTransactionManager $transactions,
        public SavedInUnits $savedInUnits,
    ) {
    }

    #[Override]
    public function save(Image $image): void
    {
        $this->savedInUnits->units[] = $this->transactions->current;
        $this->rows->save($image);
    }

    #[Override]
    public function remove(Image $image): void
    {
        $this->rows->remove($image);
    }

    #[Override]
    public function findById(ImageId $id): ?Image
    {
        return $this->rows->findById($id);
    }

    #[Override]
    public function idsByOrigin(DerivativeOrigin $origin, int $limit, ?ImageId $after = null): array
    {
        return $this->rows->idsByOrigin($origin, $limit, $after);
    }

    #[Override]
    public function originIdsOfType(string $type, int $limit, ?string $afterId = null): array
    {
        return $this->rows->originIdsOfType($type, $limit, $afterId);
    }
}
