<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Images\Application;

use Erpify\Shared\Images\Domain\ImageId;
use Erpify\Shared\Images\Domain\Storage\ImageBytesNotFound;
use Erpify\Shared\Images\Domain\Storage\ImageStorage;
use Erpify\Shared\Images\Domain\Storage\ImageStorageFailed;
use Erpify\Shared\Images\Domain\Storage\StorageOperation;
use Override;

/**
 * A usable alternative implementation of the port, not a stub: it keeps what it is given and honours the
 * same contract, so a use-case test exercises real sequencing rather than a recorded call.
 *
 * It cannot stand in for the real adapter where the property under test is a property of a FILESYSTEM —
 * a partial write, an untraversable directory — which is why the adapter has its own tests against a real
 * temporary root.
 *
 * @internal
 */
final class InMemoryImageStorage implements ImageStorage
{
    /** @var array<string, string> */
    public array $objects = [];

    #[Override]
    public function store(ImageId $id, string $bytes): void
    {
        if (isset($this->objects[$id->toString()])) {
            throw new ImageStorageFailed(StorageOperation::Store, 'the identifier already carries an object');
        }

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
        unset($this->objects[$id->toString()]);
    }
}
