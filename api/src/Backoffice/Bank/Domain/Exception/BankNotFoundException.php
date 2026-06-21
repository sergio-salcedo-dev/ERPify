<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;
use Erpify\Shared\ErrorContract\Domain\Exception\NotFound;

final class BankNotFoundException extends DomainException implements NotFound
{
    public static function withId(string $id): self
    {
        return new self(
            type: 'bank-not-found',
            title: \sprintf('Bank with id <%s> not found.', $id),
            context: ['bankId' => $id],
        );
    }
}
