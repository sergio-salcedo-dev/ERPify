<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Domain\Enum;

enum BankAccountStatus: string
{
    case ACTIVE = 'ACTIVE';
    case INACTIVE = 'INACTIVE';
    case CLOSED = 'CLOSED';
}
