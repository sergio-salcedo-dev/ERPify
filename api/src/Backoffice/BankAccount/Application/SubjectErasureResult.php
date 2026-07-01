<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Application;

/**
 * The outcome of a GDPR subject erasure: the scope it targeted, whether a live record was removed and
 * whether a key was destroyed. Either being true means something was forgotten; both false is a no-op
 * re-run (the subject was already erased).
 */
final readonly class SubjectErasureResult
{
    public function __construct(
        public string $encryptionScopeId,
        public bool $liveRecordErased,
        public bool $keyDestroyed,
    ) {
    }

    public function erasedAnything(): bool
    {
        return $this->liveRecordErased || $this->keyDestroyed;
    }
}
