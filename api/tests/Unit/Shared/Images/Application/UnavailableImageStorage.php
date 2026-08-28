<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Images\Application;

use Erpify\Shared\Images\Domain\ImageId;
use Erpify\Shared\Images\Domain\Storage\ImageStorage;
use Erpify\Shared\Images\Domain\Storage\ImageStorageUnavailable;
use Erpify\Shared\Images\Domain\Storage\StorageOperation;
use Override;

/**
 * A storage whose every operation fails in the retryable class, so a test can observe what the caller does
 * with the failure rather than what it does with the bytes.
 *
 * @internal
 */
final class UnavailableImageStorage implements ImageStorage
{
    #[Override]
    public function store(ImageId $id, string $bytes): void
    {
        throw new ImageStorageUnavailable(StorageOperation::Store);
    }

    #[Override]
    public function read(ImageId $id): string
    {
        throw new ImageStorageUnavailable(StorageOperation::Read);
    }

    #[Override]
    public function delete(ImageId $id): void
    {
        throw new ImageStorageUnavailable(StorageOperation::Delete);
    }
}
