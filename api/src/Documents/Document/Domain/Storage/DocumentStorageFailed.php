<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Domain\Storage;

/**
 * The substrate refused, or an answer could not be established: an unmounted root, a directory that cannot be
 * traversed, a full disk, a write cut short. The `reason` is one of the adapter's fixed sentences.
 */
final class DocumentStorageFailed extends DocumentStorageException
{
    public static function because(StorageArea $area, string $reason): self
    {
        return new self($area, $reason);
    }
}
