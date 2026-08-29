<?php

declare(strict_types=1);

namespace Erpify\Shared\Images\Domain\Repository;

use Erpify\Shared\Images\Domain\Entity\Image;
use Erpify\Shared\Images\Domain\ImageId;

/**
 * Aggregate-lifecycle port backed by the system of record. There is no search port beside it: this
 * module answers by identity only, and nothing here lists or filters images.
 *
 * The whole surface speaks {@see ImageId}. No method accepts or returns a path, a URL or a storage
 * key — where the bytes live is the storage port's business and is never part of this contract.
 */
interface ImageRepository
{
    /**
     * Writes the aggregate. It synchronises the unit of work so a refused write surfaces here, where it
     * can be translated, rather than as a raw driver exception at commit time; it does not COMMIT, and
     * it does not decide the transaction boundary, which belongs to the surrounding
     * `TransactionManager::transactional()`.
     *
     * @throws \Erpify\Shared\Persistence\Domain\Exception\ConcurrentUniqueWrite when the identity is taken
     */
    public function save(Image $image): void;

    /**
     * Deletes the row, and is NOT a lifecycle API. Only the physical-deletion handler may call it: what
     * decides that an image is no longer needed is the consuming aggregate, never this module. Exposed
     * as a general primitive, nothing would stop other code from deleting the row outside that protocol
     * and stranding the stored object with no reference to it — the bookkeeping this module refuses to
     * build, and therefore cannot use to find it again.
     */
    public function remove(Image $image): void;

    /**
     * `null` means the row is CONFIRMED ABSENT, never that the lookup failed: a database failure raises.
     * The distinction is load-bearing on the deletion path, where reading "I could not look" as "it is
     * not there" reports an erasure that never happened.
     */
    public function findById(ImageId $id): ?Image;
}
