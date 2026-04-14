<?php

declare(strict_types=1);

namespace Erpify\Shared\Infrastructure\Persistence;

use Erpify\Shared\Infrastructure\Persistence\Entity\StoredDomainEvent;

/**
 * Write-side persistence for {@see StoredDomainEvent} audit rows.
 *
 * This is infrastructure only (not a domain aggregate repository). It keeps Doctrine details out of
 * {@see DoctrineDomainEventStore} and gives one place to add queries later (e.g. admin read APIs).
 */
interface StoredDomainEventRepository
{
    public function save(StoredDomainEvent $storedDomainEvent): void;
}
