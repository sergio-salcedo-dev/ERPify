<?php

declare(strict_types=1);

namespace Erpify\Shared\Images\Domain\Storage;

use RuntimeException;

/**
 * The object is demonstrably absent: a check that could have seen it, had it been there, did not.
 *
 * Only raised where absence is a distinguishable outcome, which is the read path. On the delete path
 * absence is success, never this.
 *
 * The key is deliberately absent from the message. It is derived from the identifier, so quoting it
 * would put that identifier into the exception text — and an exception's text reaches
 * `messenger_messages` through `ErrorDetailsStamp` and the error reporter, neither of which any erasure
 * path can reach. The same reasoning `ConcurrentUniqueWrite` records for the driver's own message.
 */
final class ImageBytesNotFound extends RuntimeException implements ImageStorageException
{
    public function __construct()
    {
        parent::__construct('No stored object exists for the requested image.');
    }

    public function storageFailure(): StorageFailureCategory
    {
        return StorageFailureCategory::ConfirmedAbsence;
    }

    public function operation(): StorageOperation
    {
        return StorageOperation::Read;
    }
}
