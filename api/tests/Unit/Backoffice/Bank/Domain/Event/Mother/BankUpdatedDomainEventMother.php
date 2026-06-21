<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\Bank\Domain\Event\Mother;

use DateTimeImmutable;
use Erpify\Backoffice\Bank\Domain\Event\BankSnapshot;
use Erpify\Backoffice\Bank\Domain\Event\BankUpdatedDomainEvent;
use Erpify\Tests\Unit\Backoffice\Bank\Domain\Entity\Mother\BankMother;

final class BankUpdatedDomainEventMother
{
    public static function create(
        string $aggregateId = BankMother::DEFAULT_ID,
        ?BankSnapshot $snapshot = null,
        ?string $eventId = null,
        ?string $occurredOn = null,
    ): BankUpdatedDomainEvent {
        return new BankUpdatedDomainEvent(
            $aggregateId,
            $snapshot ?? BankSnapshotMother::create(),
            $eventId,
            null === $occurredOn ? null : new DateTimeImmutable($occurredOn),
        );
    }
}
