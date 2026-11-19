<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Images\Application;

use Erpify\Shared\Images\Domain\DerivativeOrigin;
use Erpify\Shared\Images\Domain\Entity\Image;
use Erpify\Shared\Images\Domain\ImageId;
use Erpify\Shared\Images\Domain\Repository\ImageRepository;
use Erpify\Shared\Persistence\Domain\Exception\ConcurrentUniqueWrite;
use Override;

/**
 * A usable alternative implementation of the lifecycle port, honouring the same contract: identity is
 * unique, `findById()` answers `null` only for a confirmed absence, and `remove()` is idempotent.
 *
 * @internal
 */
final class InMemoryImageRepository implements ImageRepository
{
    /** @var array<string, Image> */
    public array $rows = [];

    #[Override]
    public function save(Image $image): void
    {
        if (isset($this->rows[$image->id()->toString()])) {
            throw ConcurrentUniqueWrite::onWrite('image');
        }

        $this->rows[$image->id()->toString()] = $image;
    }

    #[Override]
    public function remove(Image $image): void
    {
        unset($this->rows[$image->id()->toString()]);
    }

    #[Override]
    public function findById(ImageId $id): ?Image
    {
        return $this->rows[$id->toString()] ?? null;
    }

    #[Override]
    public function idsByOrigin(DerivativeOrigin $origin, int $limit, ?ImageId $after = null): array
    {
        $ids = [];

        foreach ($this->rows as $image) {
            $held = $image->origin();
            $id = $image->id()->toString();

            if (
                null !== $held
                && $held->type === $origin->type
                && $held->id === $origin->id
                && (!$after instanceof ImageId || \strcmp($id, $after->toString()) > 0)
            ) {
                $ids[] = $id;
            }
        }

        \sort($ids);

        return \array_map(ImageId::fromString(...), \array_slice($ids, 0, $limit));
    }

    #[Override]
    public function originIdsOfType(string $type, int $limit, ?string $afterId = null): array
    {
        $ids = [];

        foreach ($this->rows as $image) {
            $origin = $image->origin();

            if (null === $origin || $origin->type !== $type) {
                continue;
            }

            if (null === $afterId || \strcmp($origin->id, $afterId) > 0) {
                $ids[$origin->id] = $origin->id;
            }
        }

        \sort($ids);

        return \array_slice($ids, 0, $limit);
    }
}
