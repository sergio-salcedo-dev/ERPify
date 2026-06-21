<?php

declare(strict_types=1);

namespace Erpify\Shared\Search\Infrastructure\Persistence\Doctrine;

use Erpify\Shared\Search\Domain\PaginationMode;

/**
 * Typed replacement for the stringly-typed {@see \Erpify\Shared\Infrastructure\Persistence\Doctrine\PaginatorOption}
 * options bag. Lives at the search-seam root (`…\Doctrine\Search\`), NOT under
 * `Keyset/` — it configures the engine, not the keyset mechanism (AR12).
 *
 * Minimal by design (YAGNI): only the options the keyset engine will actually
 * read. `enableCursorPagination` is gone — keyset is the single pagination
 * kernel, never toggled off. PR2 refines this as the engine consumes it.
 */
final readonly class PaginatorConfig
{
    /**
     * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
     */
    public function __construct(
        public PaginationMode $paginationMode = PaginationMode::LIGHT,
        public bool $fetchJoinCollection = true,
    ) {
    }
}
