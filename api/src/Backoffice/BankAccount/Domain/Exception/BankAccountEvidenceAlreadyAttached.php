<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\Conflict;
use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;

/**
 * A bank account already holds this document as evidence — this account or another: a document is evidence of ONE
 * account. Detach it first to attach it elsewhere. The context names the document only, never the holding account.
 */
final class BankAccountEvidenceAlreadyAttached extends DomainException implements Conflict
{
    public static function of(string $documentId): self
    {
        return new self(
            type: 'bank-account-evidence-already-attached',
            title: 'The document is already attached to a bank account.',
            context: ['documentId' => $documentId],
        );
    }
}
