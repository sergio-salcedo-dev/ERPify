<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Domain\Exception;

use DomainException;

final class BankNotFoundException extends DomainException
{
    public static function withId(string $id): self
    {
        return new self(\sprintf('Bank with id <%s> not found.', $id));
    }
}
