<?php

declare(strict_types=1);

namespace Erpify\Shared\Infrastructure\Persistence;

interface PaginatorCursorInterface
{
    public function getCurrentPage(): ?int;

    public function setCurrentPage(?int $currentPage): self;

    /** @return array<string, mixed> */
    public function getFirstItem(): array;

    /** @param array<string, mixed> $item */
    public function setFirstItem(array $item): self;

    /** @return array<string, mixed> */
    public function getLastItem(): array;

    /** @param array<string, mixed> $item */
    public function setLastItem(array $item): self;

    public function getCount(): ?int;

    public function setCount(?int $count): self;
}
