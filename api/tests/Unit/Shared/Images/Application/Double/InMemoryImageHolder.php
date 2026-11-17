<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Images\Application\Double;

use Erpify\Shared\Images\Application\ImageHolder;
use Erpify\Shared\Images\Domain\ImageId;
use Override;
use RuntimeException;

/**
 * An owner table of one column: `ownerId => image id or null`, the shape every consumer's aggregate reduces to as far
 * as {@see ImageHolder} is concerned. It notes which unit of work each `lockAndRead` ran in, which is what the
 * "no unit takes both the owner row and an image row" assertion is made over.
 *
 * @internal
 */
final class InMemoryImageHolder implements ImageHolder, Snapshottable
{
    /** Raised by `attach` when set, so a case can fail the unit of work after the lock. */
    public ?RuntimeException $attachFails = null;

    /** Raised by every `lockAndRead` once `attach` has been called, so a case can fail the compensation's re-read. */
    public ?RuntimeException $rereadFails = null;

    private bool $attachCalled = false;

    /**
     * The unit of work each `lockAndRead` ran in.
     *
     * @var list<int>
     */
    public array $lockedInUnits = [];

    /**
     * @param array<string, ImageId|null> $held
     */
    public function __construct(
        private array $held,
        private readonly RollbackingTransactionManager $transactions,
    ) {
        $transactions->track($this);
    }

    #[Override]
    public function lockAndRead(string $ownerId): ?ImageId
    {
        $this->lockedInUnits[] = $this->transactions->current;

        if ($this->attachCalled && $this->rereadFails instanceof RuntimeException) {
            throw $this->rereadFails;
        }

        if (!\array_key_exists($ownerId, $this->held)) {
            throw new OwnerNotFound();
        }

        return $this->held[$ownerId];
    }

    #[Override]
    public function attach(string $ownerId, ?ImageId $imageId): void
    {
        $this->attachCalled = true;
        $this->held[$ownerId] = $imageId;

        if ($this->attachFails instanceof RuntimeException) {
            throw $this->attachFails;
        }
    }

    public function holds(string $ownerId): ?ImageId
    {
        return $this->held[$ownerId] ?? null;
    }

    public function remove(string $ownerId): void
    {
        unset($this->held[$ownerId]);
    }

    #[Override]
    public function snapshot(): mixed
    {
        return $this->held;
    }

    #[Override]
    public function restore(mixed $snapshot): void
    {
        \assert(\is_array($snapshot));
        /** @var array<string, ImageId|null> $snapshot */
        $this->held = $snapshot;
    }
}
