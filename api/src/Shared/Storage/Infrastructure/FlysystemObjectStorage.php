<?php

declare(strict_types=1);

namespace Erpify\Shared\Storage\Infrastructure;

use Erpify\Shared\Storage\Application\Port\ObjectStoragePort;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\UnableToReadFile;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Target;

#[AsAlias(ObjectStoragePort::class)]
final readonly class FlysystemObjectStorage implements ObjectStoragePort
{
    public function __construct(
        #[Target('erpify.object_storage.storage')]
        private FilesystemOperator $filesystemOperator,
    ) {
    }

    #[\Override]
    public function write(string $key, string $contents): void
    {
        $this->filesystemOperator->write($key, $contents);
    }

    #[\Override]
    public function read(string $key): string
    {
        try {
            return $this->filesystemOperator->read($key);
        } catch (UnableToReadFile $unableToReadFile) {
            throw new RuntimeException(\sprintf('Cannot read object storage key "%s".', $key), 0, $unableToReadFile);
        }
    }

    #[\Override]
    public function delete(string $key): void
    {
        if (!$this->filesystemOperator->fileExists($key)) {
            return;
        }

        $this->filesystemOperator->delete($key);
    }

    #[\Override]
    public function exists(string $key): bool
    {
        return $this->filesystemOperator->fileExists($key);
    }
}
