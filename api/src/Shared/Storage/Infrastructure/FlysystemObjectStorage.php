<?php

declare(strict_types=1);

namespace Erpify\Shared\Storage\Infrastructure;

use Erpify\Shared\Storage\Application\Port\ObjectStoragePort;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\UnableToReadFile;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Target;

#[AsAlias(ObjectStoragePort::class)]
final class FlysystemObjectStorage implements ObjectStoragePort
{
    public function __construct(
        #[Target('erpify.object_storage.storage')]
        private readonly FilesystemOperator $filesystem,
    ) {
    }

    public function write(string $key, string $contents): void
    {
        $this->filesystem->write($key, $contents);
    }

    public function read(string $key): string
    {
        try {
            return $this->filesystem->read($key);
        } catch (UnableToReadFile $e) {
            throw new \RuntimeException(sprintf('Cannot read object storage key "%s".', $key), 0, $e);
        }
    }

    public function delete(string $key): void
    {
        if (!$this->filesystem->fileExists($key)) {
            return;
        }

        $this->filesystem->delete($key);
    }

    public function exists(string $key): bool
    {
        return $this->filesystem->fileExists($key);
    }
}
