<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\Conflict;
use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;

/**
 * The document to attach is quarantined: its scan found it infected, so it is never served and can prove nothing an
 * account could rely on. A document whose scan failed stays attachable, because an operator may re-scan it.
 */
final class BankAccountEvidenceDocumentNotAdmissible extends DomainException implements Conflict
{
    public static function withId(string $documentId): self
    {
        return new self(
            type: 'bank-account-evidence-document-not-admissible',
            title: 'The document to attach is quarantined.',
            context: ['documentId' => $documentId],
        );
    }
}
