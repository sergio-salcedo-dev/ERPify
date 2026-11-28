<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\Conflict;
use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;

/**
 * The account is `CLOSED`: it is on its way to deletion, and a new piece of evidence would only stand in the way of
 * that deletion. Reopen the account first. A detach stays possible.
 */
final class BankAccountEvidenceAccountClosed extends DomainException implements Conflict
{
    public static function withId(string $bankAccountId): self
    {
        return new self(
            type: 'bank-account-evidence-account-closed',
            title: 'Cannot attach evidence to a closed bank account.',
            context: ['bankAccountId' => $bankAccountId],
        );
    }
}
