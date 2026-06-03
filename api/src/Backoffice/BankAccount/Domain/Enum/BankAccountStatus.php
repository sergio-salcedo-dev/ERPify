<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Domain\Enum;

use Erpify\Shared\Domain\Enum\Abstraction\HumanReadableIntEnumInterface;
use Erpify\Shared\Domain\Enum\Abstraction\HumanReadableIntEnumTrait;
use Erpify\Shared\Domain\Enum\Attribute\HumanReadableIntEnumValue;

enum BankAccountStatus: int implements HumanReadableIntEnumInterface
{
    use HumanReadableIntEnumTrait;

    #[HumanReadableIntEnumValue(label: 'active')]
    case ACTIVE = 1;

    #[HumanReadableIntEnumValue(label: 'inactive')]
    case INACTIVE = 2;

    #[HumanReadableIntEnumValue(label: 'closed')]
    case CLOSED = 3;
}
