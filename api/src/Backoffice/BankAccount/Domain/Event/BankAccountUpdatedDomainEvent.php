<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Domain\Event;

use Erpify\Shared\Event\Domain\DomainEvent;
use Override;

final class BankAccountUpdatedDomainEvent extends DomainEvent
{
    use CarriesBankAccountSnapshot;

    #[Override]
    public static function eventName(): string
    {
        return 'erpify.backoffice.bankaccount.updated';
    }
}
