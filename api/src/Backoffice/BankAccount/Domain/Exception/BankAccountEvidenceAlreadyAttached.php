<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\Conflict;
use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;

/**
 * The account already holds this document as evidence. A second link would say nothing the first does not; a
 * different kind for the same document is a detach and a new attach, which no route offers yet.
 */
final class BankAccountEvidenceAlreadyAttached extends DomainException implements Conflict
{
    public static function of(string $bankAccountId, string $documentId): self
    {
        return new self(
            type: 'bank-account-evidence-already-attached',
            title: 'The document is already attached to this bank account.',
            context: ['bankAccountId' => $bankAccountId, 'documentId' => $documentId],
        );
    }
}
