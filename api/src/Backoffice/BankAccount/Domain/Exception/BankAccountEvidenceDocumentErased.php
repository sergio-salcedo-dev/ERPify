<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\Conflict;
use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;

/**
 * The document to attach is erased: its key is destroyed and only the tombstone remains, so it can prove nothing new.
 * A link made before the erasure stays and lists the tombstone; this refuses only a new one.
 */
final class BankAccountEvidenceDocumentErased extends DomainException implements Conflict
{
    public static function withId(string $documentId): self
    {
        return new self(
            type: 'bank-account-evidence-document-erased',
            title: 'The document to attach has been erased.',
            context: ['documentId' => $documentId],
        );
    }
}
