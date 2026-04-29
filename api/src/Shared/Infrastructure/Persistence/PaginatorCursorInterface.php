<?php

declare(strict_types=1);

namespace Erpify\Shared\Infrastructure\Persistence;

use Erpify\Shared\Domain\Search\SearchCursor;
use Override;

interface PaginatorCursorInterface extends SearchCursor
{
    #[Override]
    public function getCurrentPage(): ?int;

    public function setCurrentPage(?int $currentPage): self;

    /** @return array<string, mixed> */
    #[Override]
    public function getFirstItem(): array;

    /** @param array<string, mixed> $item */
    public function setFirstItem(array $item): self;

    /** @return array<string, mixed> */
    #[Override]
    public function getLastItem(): array;

    /** @param array<string, mixed> $item */
    public function setLastItem(array $item): self;

    #[Override]
    public function getCount(): ?int;

    public function setCount(?int $count): self;
}
