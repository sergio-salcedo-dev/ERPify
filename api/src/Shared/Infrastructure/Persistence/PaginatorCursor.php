<?php

declare(strict_types=1);

namespace Erpify\Shared\Infrastructure\Persistence;

use Override;

class PaginatorCursor implements PaginatorCursorInterface
{
    /**
     * @param array<string, mixed> $firstItem
     * @param array<string, mixed> $lastItem
     */
    public function __construct(
        private ?int $currentPage = null,
        private ?int $count = null,
        private array $firstItem = [],
        private array $lastItem = [],
    ) {
    }

    #[Override]
    public function getCurrentPage(): ?int
    {
        return $this->currentPage;
    }

    #[Override]
    public function setCurrentPage(?int $currentPage): PaginatorCursorInterface
    {
        $this->currentPage = $currentPage;

        return $this;
    }

    #[Override]
    public function getFirstItem(): array
    {
        return $this->firstItem;
    }

    #[Override]
    public function setFirstItem(array $item): PaginatorCursorInterface
    {
        $this->firstItem = $item;

        return $this;
    }

    #[Override]
    public function getLastItem(): array
    {
        return $this->lastItem;
    }

    #[Override]
    public function setLastItem(array $item): PaginatorCursorInterface
    {
        $this->lastItem = $item;

        return $this;
    }

    #[Override]
    public function getCount(): ?int
    {
        return $this->count;
    }

    #[Override]
    public function setCount(?int $count): PaginatorCursorInterface
    {
        $this->count = $count;

        return $this;
    }
}
