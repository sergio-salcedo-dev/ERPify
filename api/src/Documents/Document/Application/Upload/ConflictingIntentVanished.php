<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application\Upload;

use RuntimeException;

/**
 * The insert lost to an intent holding the same key, and that intent was gone before it could be read (its
 * retention sweep or the reaper removed it in between). The declare runs once more; this is the single retry
 * the idempotency path allows. Internal to {@see DeclareUpload}.
 */
final class ConflictingIntentVanished extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The intent holding the idempotency key vanished before it could be read.');
    }
}
