<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application\Listing;

/**
 * Why a document's sealed name did not open, as the scheduled report counts it. A wire value never carries it: the
 * listing's `name` is simply null.
 */
enum NameCause: string
{
    /** The scope's key is destroyed or was never minted (`dek-destroyed`). */
    case KeyMissing = 'key-missing';

    /** Authentication failed, or the key row is corrupt (`decryption-failed`). */
    case DecryptionFailed = 'decryption-failed';

    /** Any other per-document failure: a scope that cannot be named, a plaintext that is not text. */
    case Unreadable = 'unreadable';
}
