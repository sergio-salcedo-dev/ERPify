<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\Conflict;
use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;

/**
 * Raised when a bank account is deleted while not in the terminal `CLOSED` state. The invariant lives
 * in the aggregate (Tell-Don't-Ask); this is the state-based sibling of the bank's referential-integrity
 * 409, mapped by the RFC 9457 pipeline through the {@see Conflict} marker.
 */
final class BankAccountNotClosedException extends DomainException implements Conflict
{
    public static function withId(string $id): self
    {
        return new self(
            type: 'bank-account-not-closed',
            title: 'Cannot delete the bank account because it is not closed.',
            context: ['bankAccountId' => $id],
        );
    }
}
