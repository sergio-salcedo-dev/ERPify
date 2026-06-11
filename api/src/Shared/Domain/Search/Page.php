<?php

declare(strict_types=1);

namespace Erpify\Shared\Domain\Search;

/**
 * Domain port for a single page of a keyset-paginated search result.
 *
 * Holds the page items plus the navigation metadata the wire envelope is built
 * from in PR3: whether a next/previous page exists, an optional total count
 * (null in light pagination, where no COUNT runs) and the opaque cursors that
 * position the adjacent pages.
 *
 * The cursor fields are **opaque transport tokens**: the domain makes no
 * assumption about their structure, decodability or validity beyond string
 * identity — it treats a cursor as an id. {@see Page} is therefore NOT a wire
 * DTO; the HTTP envelope (with relative `links`) is assembled by the responder
 * in PR3. Cero imports de framework (NFR5).
 *
 * @template-covariant T
 */
final readonly class Page
{
    /**
     * @param list<T> $items
     */
    public function __construct(
        public array $items,
        public bool $hasNext,
        public bool $hasPrev,
        public ?int $count = null,
        public ?string $nextCursor = null,
        public ?string $prevCursor = null,
    ) {
    }

    /**
     * @return self<T>
     */
    public static function empty(): self
    {
        return new self(items: [], hasNext: false, hasPrev: false);
    }

    public function isEmpty(): bool
    {
        return [] === $this->items;
    }
}
