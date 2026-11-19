<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Documents\Document;

use Erpify\Shared\Images\Domain\DerivativeOrigin;
use Erpify\Shared\Images\Domain\Entity\Image;
use Erpify\Shared\Images\Domain\ImageId;
use Erpify\Shared\Images\Domain\Repository\ImageRepository;
use Override;
use Throwable;

/**
 * The real repository, except that the origin pages the orphan sweep walks are scripted: a stale page reads an origin
 * whose derivatives are already gone, and a {@see Throwable} in the script is what the read of that page throws.
 */
final class ScriptedOriginPages implements ImageRepository
{
    /**
     * @param list<list<string>|Throwable> $pages
     */
    public function __construct(
        private readonly ImageRepository $inner,
        private array $pages,
    ) {
    }

    #[Override]
    public function save(Image $image): void
    {
        $this->inner->save($image);
    }

    #[Override]
    public function remove(Image $image): void
    {
        $this->inner->remove($image);
    }

    #[Override]
    public function findById(ImageId $id): ?Image
    {
        return $this->inner->findById($id);
    }

    #[Override]
    public function idsByOrigin(DerivativeOrigin $origin, int $limit, ?ImageId $after = null): array
    {
        return $this->inner->idsByOrigin($origin, $limit, $after);
    }

    #[Override]
    public function originIdsOfType(string $type, int $limit, ?string $afterId = null): array
    {
        $page = \array_shift($this->pages) ?? [];

        if ($page instanceof Throwable) {
            throw $page;
        }

        return $page;
    }
}
