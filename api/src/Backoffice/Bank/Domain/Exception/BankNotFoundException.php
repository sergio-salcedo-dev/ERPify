<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Domain\Exception;

use DomainException;
use Symfony\Component\Uid\Uuid;

final class BankNotFoundException extends DomainException
{
    public static function withId(Uuid $id): self
    {
        return new self(sprintf('Bank with id <%s> not found.', (string) $id));
    }
}
