<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Domain\Event;

use Override;

final class BankCreatedDomainEvent extends AbstractBankChangedDomainEvent
{
    #[Override]
    public static function eventName(): string
    {
        return 'erpify.backoffice.bank.created';
    }
}
