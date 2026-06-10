<?php

declare(strict_types=1);

namespace Erpify\Shared\Infrastructure\Http\Responder;

use Erpify\Shared\Domain\Search\PaginatedResult;

/**
 * Typed wire shape of the `pagination` envelope siblings emitted alongside the
 * `data` array on collection responses. Centralizes the response contract that
 * the PWA, Behat scenarios, and metrics key off, so the keys live in one place
 * instead of an inline literal in {@see SearchResponder}.
 *
 * Cursor encoding (HMAC + base64) stays in infrastructure: the caller passes
 * the already-encoded string, this value object never touches the encoder.
 */
final readonly class PaginationMeta
{
    public function __construct(
        public int $currentPage,
        public ?int $pageCount,
        public ?int $count,
        public bool $hasMorePages,
        public string $cursor,
    ) {
    }

    /**
     * @param PaginatedResult<object> $result
     * @param string                  $cursor the encoded cursor string for the next page
     */
    public static function fromPaginatedResult(PaginatedResult $result, string $cursor): self
    {
        return new self(
            $result->getCurrentPage(),
            $result->getPageCount(),
            $result->getCount(),
            $result->hasMorePages(),
            $cursor,
        );
    }

    /**
     * @return array{currentPage: int, pageCount: int|null, count: int|null, hasMorePages: bool, cursor: string}
     */
    public function toArray(): array
    {
        return [
            'currentPage' => $this->currentPage,
            'pageCount' => $this->pageCount,
            'count' => $this->count,
            'hasMorePages' => $this->hasMorePages,
            'cursor' => $this->cursor,
        ];
    }
}
