<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Domain\Storage;

/**
 * The key the operation was to create is taken. The existing object is untouched.
 */
final class ObjectAlreadyExists extends DocumentStorageException
{
    public static function in(StorageArea $area): self
    {
        return new self($area, 'the storage key already carries an object');
    }
}
