<?php

declare(strict_types=1);

namespace Erpify\Shared\Crypto\Domain;

/**
 * Why a scope's key was destroyed, recorded on the keystore tombstone. The two reasons are not
 * interchangeable: an `Erasure` tombstone answers to a recorded erasure and is reconciled against it, while an
 * `AbortedUpload` tombstone closes a scope whose write never completed and has nothing to reconcile.
 */
enum DestroyReason: string
{
    case AbortedUpload = 'aborted-upload';
    case Erasure = 'erasure';
}
