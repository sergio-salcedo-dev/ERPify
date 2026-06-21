<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\Bank\Domain\Event\Mother;

use DateTimeImmutable;
use Erpify\Backoffice\Bank\Domain\Event\BankDeletedDomainEvent;
use Erpify\Tests\Unit\Backoffice\Bank\Domain\Entity\Mother\BankMother;

final class BankDeletedDomainEventMother
{
    public static function create(
        string $aggregateId = BankMother::DEFAULT_ID,
        ?string $eventId = null,
        ?string $occurredOn = null,
    ): BankDeletedDomainEvent {
        return new BankDeletedDomainEvent(
            $aggregateId,
            $eventId,
            null === $occurredOn ? null : new DateTimeImmutable($occurredOn),
        );
    }
}
