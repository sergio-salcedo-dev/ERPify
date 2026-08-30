<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Images\Application;

use Erpify\Shared\Images\Domain\ImageId;
use Erpify\Shared\Images\Domain\Storage\ImageStorage;
use Override;
use RuntimeException;

/**
 * A storage that answers a read with bytes OTHER than the ones it was given, which is the only way to reach
 * the digest comparison: every honest double returns what it stored, so the verification arm is unreachable
 * without a substrate that lies.
 *
 * @internal
 */
final readonly class CorruptingImageStorage implements ImageStorage
{
    public function __construct(private string $bytesToReturn)
    {
    }

    #[Override]
    public function store(ImageId $id, string $bytes): void
    {
        throw new RuntimeException('This double exists for the read path only.');
    }

    #[Override]
    public function read(ImageId $id): string
    {
        return $this->bytesToReturn;
    }

    #[Override]
    public function delete(ImageId $id): void
    {
        throw new RuntimeException('This double exists for the read path only.');
    }
}
