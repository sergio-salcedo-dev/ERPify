<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Documents\Document;

use Closure;
use Erpify\Documents\Document\Domain\Storage\DocumentStorage;
use Erpify\Documents\Document\Domain\Storage\StorageArea;
use Override;

/**
 * A {@see DocumentStorage} that delegates everything and runs a callback just before each `open()`: the seam where a
 * test commits, from a second connection, what a concurrent erasure commits between a read's snapshot and its open.
 *
 * @internal
 */
final readonly class DocumentStorageThatRunsBeforeOpen implements DocumentStorage
{
    public function __construct(
        private DocumentStorage $inner,
        private Closure $beforeOpen,
    ) {
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
        ($this->beforeOpen)();

        return $this->inner->open($scopeId);
    }

    #[Override]
    public function delete(StorageArea $area, string $scopeId): void
    {
        $this->inner->delete($area, $scopeId);
    }
}
