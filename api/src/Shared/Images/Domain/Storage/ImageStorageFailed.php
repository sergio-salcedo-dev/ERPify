<?php

declare(strict_types=1);

namespace Erpify\Shared\Images\Domain\Storage;

use RuntimeException;

/**
 * The substrate failed in a way no retry resolves: a root that is not there, a directory that cannot be
 * traversed, an identifier already carrying an object, no space left.
 *
 * Distinguishing this from {@see ImageStorageUnavailable} is what stops a consumer retrying for ever
 * against something only an operator can fix. Carries neither the key nor the library's exception, for
 * the reason recorded on its sibling.
 */
final class ImageStorageFailed extends RuntimeException implements ImageStorageException
{
    public function __construct(private readonly StorageOperation $operation, string $reason)
    {
        parent::__construct(\sprintf('Image storage failed permanently during "%s": %s', $operation->value, $reason));
    }

    public function storageFailure(): StorageFailureCategory
    {
        return StorageFailureCategory::Permanent;
    }

    public function operation(): StorageOperation
    {
        return $this->operation;
    }
}
