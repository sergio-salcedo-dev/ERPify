<?php

declare(strict_types=1);

namespace Erpify\Shared\Images\Domain\Storage;

use RuntimeException;

/**
 * The substrate failed in a way a retry can plausibly resolve. Carries neither the key nor the library's
 * own exception — not even as `previous` — because both name the path, and the path contains the image
 * identifier.
 */
final class ImageStorageUnavailable extends RuntimeException implements ImageStorageException
{
    public function __construct(private readonly StorageOperation $operation)
    {
        parent::__construct(\sprintf('Image storage is temporarily unavailable during "%s".', $operation->value));
    }

    public function storageFailure(): StorageFailureCategory
    {
        return StorageFailureCategory::Transient;
    }

    public function operation(): StorageOperation
    {
        return $this->operation;
    }
}
