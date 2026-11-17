<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Images\Application\Double;

use Erpify\Shared\Images\Domain\ImageId;
use Erpify\Shared\Images\Domain\Storage\ImageStorage;
use Erpify\Tests\Unit\Shared\Images\Application\InMemoryImageStorage;
use Override;

/**
 * {@see InMemoryImageStorage} that counts reads, so a case can show a request never reached the lookup.
 *
 * @internal
 */
final class CountingImageStorage implements ImageStorage
{
    public int $reads = 0;

    public function __construct(private readonly InMemoryImageStorage $inner)
    {
    }

    #[Override]
    public function store(ImageId $id, string $bytes): void
    {
        $this->inner->store($id, $bytes);
    }

    #[Override]
    public function read(ImageId $id): string
    {
        ++$this->reads;

        return $this->inner->read($id);
    }

    #[Override]
    public function delete(ImageId $id): void
    {
        $this->inner->delete($id);
    }
}
