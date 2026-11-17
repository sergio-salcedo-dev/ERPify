<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Documents\Document;

use Erpify\Documents\Document\Domain\Storage\DocumentStorage;
use Erpify\Documents\Document\Domain\Storage\StorageArea;
use Override;

/**
 * The real document storage whose `delete()` reports success and removes nothing: the shape of a volume that answers
 * "done" for bytes that are still there, which is why the sweep re-checks both areas before it marks a row done.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.TooManyPublicMethods") the storage port, delegated
 */
final readonly class RetainingDocumentStorage implements DocumentStorage
{
    public function __construct(private DocumentStorage $inner)
    {
    }

    #[Override]
    public function stage(string $scopeId, $stream): void
    {
        $this->inner->stage($scopeId, $stream);
    }

    #[Override]
    public function stageWith(string $scopeId, callable $writer): void
    {
        $this->inner->stageWith($scopeId, $writer);
    }

    #[Override]
    public function has(StorageArea $area, string $scopeId): bool
    {
        return $this->inner->has($area, $scopeId);
    }

    #[Override]
    public function scopesOlderThan(StorageArea $area, int $olderThanSeconds): iterable
    {
        return $this->inner->scopesOlderThan($area, $olderThanSeconds);
    }

    #[Override]
    public function promote(string $scopeId): void
    {
        $this->inner->promote($scopeId);
    }

    #[Override]
    public function syncFinal(string $scopeId): void
    {
        $this->inner->syncFinal($scopeId);
    }

    #[Override]
    public function open(string $scopeId)
    {
        return $this->inner->open($scopeId);
    }

    #[Override]
    public function delete(StorageArea $area, string $scopeId): void
    {
        // Reports success and leaves the object where it is.
    }
}
