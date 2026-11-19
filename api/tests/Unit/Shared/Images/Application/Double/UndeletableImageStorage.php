<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Images\Application\Double;

use Erpify\Shared\Images\Domain\ImageId;
use Erpify\Shared\Images\Domain\Storage\ImageBytesNotFound;
use Erpify\Shared\Images\Domain\Storage\ImageStorage;
use Erpify\Shared\Images\Domain\Storage\ImageStorageFailed;
use Erpify\Shared\Images\Domain\Storage\StorageOperation;
use Override;

/**
 * Stores and reads, and refuses every delete: the residue a failed compensation leaves behind.
 */
final class UndeletableImageStorage implements ImageStorage
{
    /** @var array<string, string> */
    public array $objects = [];

    #[Override]
    public function store(ImageId $id, string $bytes): void
    {
        $this->objects[$id->toString()] = $bytes;
    }

    #[Override]
    public function read(ImageId $id): string
    {
        return $this->objects[$id->toString()] ?? throw new ImageBytesNotFound();
    }

    #[Override]
    public function delete(ImageId $id): void
    {
        throw new ImageStorageFailed(StorageOperation::Delete, 'permission denied');
    }
}
