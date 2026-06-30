<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Domain\Event;

use Erpify\Shared\Event\Domain\DomainEvent;
use Override;

/**
 * Unlike a bank deletion — addressed solely by the aggregate id — an account deletion still carries
 * the PII-free snapshot: the realtime collection topic is keyed by the owning `bankId` (not the
 * account id), so the publisher needs it to notify the bank's accounts list.
 */
final class BankAccountDeletedDomainEvent extends DomainEvent
{
    use CarriesBankAccountSnapshot;

    #[Override]
    public static function eventName(): string
    {
        return 'erpify.backoffice.bankaccount.deleted';
    }
}
