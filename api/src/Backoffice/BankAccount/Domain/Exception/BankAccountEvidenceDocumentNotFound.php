<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;
use Erpify\Shared\ErrorContract\Domain\Exception\InvariantViolation;

/**
 * The body names a document no row holds. A 422 rather than a 404: the request target — the account — exists, and
 * what cannot be processed is a member of the payload.
 */
final class BankAccountEvidenceDocumentNotFound extends DomainException implements InvariantViolation
{
    public static function withId(string $documentId): self
    {
        return new self(
            type: 'bank-account-evidence-document-not-found',
            title: 'The document to attach does not exist.',
            context: ['documentId' => $documentId],
        );
    }
}
