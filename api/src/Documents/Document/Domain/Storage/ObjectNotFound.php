<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Domain\Storage;

/**
 * No object is stored under the key — demonstrably, not because the question could not be answered.
 */
final class ObjectNotFound extends DocumentStorageException
{
    public static function in(StorageArea $area): self
    {
        return new self($area, 'no object is stored under the storage key');
    }
}
