<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;
use Erpify\Shared\ErrorContract\Domain\Exception\NotFound;

final class BankAccountNotFoundException extends DomainException implements NotFound
{
    public static function withId(string $id): self
    {
        return new self(
            type: 'bank-account-not-found',
            title: \sprintf('Bank account with id <%s> not found.', $id),
            context: ['bankAccountId' => $id],
        );
    }
}
