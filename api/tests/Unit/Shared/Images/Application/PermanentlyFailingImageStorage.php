<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Images\Application;

use Erpify\Shared\Images\Domain\ImageId;
use Erpify\Shared\Images\Domain\Storage\ImageStorage;
use Erpify\Shared\Images\Domain\Storage\ImageStorageFailed;
use Erpify\Shared\Images\Domain\Storage\StorageOperation;
use Override;

/**
 * A storage whose every operation fails in the class no retry resolves, so a test can observe that the
 * permanent verdict escapes UNTRANSLATED — the property that keeps a broken substrate from being dressed
 * as a transient hiccup the caller is invited to retry.
 *
 * @internal
 */
final class PermanentlyFailingImageStorage implements ImageStorage
{
    #[Override]
    public function store(ImageId $id, string $bytes): void
    {
        throw new ImageStorageFailed(StorageOperation::Store, 'no space left on device');
    }

    #[Override]
    public function read(ImageId $id): string
    {
        throw new ImageStorageFailed(StorageOperation::Read, 'no space left on device');
    }

    #[Override]
    public function delete(ImageId $id): void
    {
        throw new ImageStorageFailed(StorageOperation::Delete, 'no space left on device');
    }
}
