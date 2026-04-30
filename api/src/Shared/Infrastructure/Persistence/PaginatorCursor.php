<?php

declare(strict_types=1);

namespace Erpify\Shared\Infrastructure\Persistence;

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

    public function getCurrentPage(): ?int
    {
        return $this->currentPage;
    }

    public function setCurrentPage(?int $currentPage): PaginatorCursorInterface
    {
        $this->currentPage = $currentPage;

        return $this;
    }

    public function getFirstItem(): array
    {
        return $this->firstItem;
    }

    public function setFirstItem(array $item): PaginatorCursorInterface
    {
        $this->firstItem = $item;

        return $this;
    }

    public function getLastItem(): array
    {
        return $this->lastItem;
    }

    public function setLastItem(array $item): PaginatorCursorInterface
    {
        $this->lastItem = $item;

        return $this;
    }

    public function getCount(): ?int
    {
        return $this->count;
    }

    public function setCount(?int $count): PaginatorCursorInterface
    {
        $this->count = $count;

        return $this;
    }
}
