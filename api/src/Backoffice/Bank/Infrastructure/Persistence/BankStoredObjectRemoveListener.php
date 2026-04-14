<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Infrastructure\Persistence;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;
use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Shared\Storage\Application\StoredObjectOrphanCleaner;

/**
 * After a bank is removed, drop the Flysystem blob only if no other aggregate still references the hash.
 */
#[AsEntityListener(event: Events::postRemove, method: 'removeStoredObjectIfOrphaned', entity: Bank::class)]
final readonly class BankStoredObjectRemoveListener
{
    public function __construct(
        private StoredObjectOrphanCleaner $storedObjectOrphanCleaner,
    ) {}

    public function removeStoredObjectIfOrphaned(Bank $bank): void
    {
        $this->storedObjectOrphanCleaner->cleanupAfterRemoval($bank->getStoredObjectContentHash());
    }
}
