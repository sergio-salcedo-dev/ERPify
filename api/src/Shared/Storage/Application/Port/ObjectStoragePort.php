<?php

declare(strict_types=1);

namespace Erpify\Shared\Storage\Application\Port;

/**
 * Application-facing object storage (Flysystem today; swappable implementation).
 */
interface ObjectStoragePort
{
    public function write(string $key, string $contents): void;

    public function read(string $key): string;

    public function delete(string $key): void;

    public function exists(string $key): bool;
}
