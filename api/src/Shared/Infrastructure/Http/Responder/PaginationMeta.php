<?php

declare(strict_types=1);

namespace Erpify\Shared\Infrastructure\Http\Responder;

use Erpify\Shared\Domain\Search\Page;

/**
 * Typed wire shape of the cursor-only `pagination` envelope emitted alongside the
 * `data` array on collection responses (PR3). Centralizes the contract the PWA,
 * Behat scenarios and metrics key off, so the keys live in one place instead of an
 * inline literal in {@see SearchResponder}.
 *
 * Shape is CONSTANT (W1): `{hasNext, hasPrev, count, links: {next, prev}}`.
 * `links.next`/`links.prev` are ALWAYS present — `null` when the affordance does not
 * apply — and `count` is `null` in light pagination. The nulls are emitted EXPLICITLY:
 * `skip_null_values` is forbidden here (AR20), so the client always sees the full shape.
 *
 * The links are pre-built relative URLs supplied by {@see SearchResponder} (the single
 * envelope compositor, W9); this value object never touches URL generation or cursors.
 */
final readonly class PaginationMeta
{
    public function __construct(
        public bool $hasNext,
        public bool $hasPrev,
        public ?int $count,
        public ?string $nextLink,
        public ?string $prevLink,
    ) {
    }

    /**
     * @param Page<object> $page
     */
    public static function fromPage(Page $page, ?string $nextLink, ?string $prevLink): self
    {
        return new self($page->hasNext, $page->hasPrev, $page->count, $nextLink, $prevLink);
    }

    /**
     * @return array{hasNext: bool, hasPrev: bool, count: int|null, links: array{next: string|null, prev: string|null}}
     */
    public function toArray(): array
    {
        return [
            'hasNext' => $this->hasNext,
            'hasPrev' => $this->hasPrev,
            'count' => $this->count,
            'links' => [
                'next' => $this->nextLink,
                'prev' => $this->prevLink,
            ],
        ];
    }
}
