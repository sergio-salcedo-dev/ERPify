<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Domain\Erasure;

/** An `Erased` row whose sealed object the deletion sweep still owes a delete. */
final readonly class ErasedObject
{
    public function __construct(
        public string $documentId,
        public string $scopeId,
    ) {
    }
}
