<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\BankAccount\Domain\Event\Mother;

use DateTimeImmutable;
use Erpify\Backoffice\BankAccount\Domain\Event\BankAccountCreatedDomainEvent;
use Erpify\Backoffice\BankAccount\Domain\Event\BankAccountSnapshot;
use Erpify\Tests\Unit\Backoffice\BankAccount\Domain\Entity\Mother\BankAccountMother;

final class BankAccountCreatedDomainEventMother
{
    public static function create(
        string $aggregateId = BankAccountMother::DEFAULT_ID,
        ?BankAccountSnapshot $snapshot = null,
        ?string $eventId = null,
        ?string $occurredOn = null,
    ): BankAccountCreatedDomainEvent {
        return new BankAccountCreatedDomainEvent(
            $aggregateId,
            $snapshot ?? BankAccountSnapshotMother::create(),
            $eventId,
            null === $occurredOn ? null : new DateTimeImmutable($occurredOn),
        );
    }
}
