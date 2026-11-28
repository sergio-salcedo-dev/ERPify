<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\Conflict;
use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;

/**
 * The account still holds evidence whose document is not erased. Removing the account would orphan a document that
 * proves something about it, so the documents are erased first — each through its own audited, re-proven erasure —
 * and only then do the account and its links go, together, in one transaction.
 */
final class BankAccountHasLiveEvidence extends DomainException implements Conflict
{
    public static function withId(string $bankAccountId): self
    {
        return new self(
            type: 'bank-account-has-live-evidence',
            title: 'Cannot remove the bank account while a document attached to it is not erased.',
            context: ['bankAccountId' => $bankAccountId],
        );
    }
}
