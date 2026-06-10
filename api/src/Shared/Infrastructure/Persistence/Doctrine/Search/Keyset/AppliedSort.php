<?php

declare(strict_types=1);

namespace Erpify\Shared\Infrastructure\Persistence\Doctrine\Search\Keyset;

use Erpify\Shared\Domain\Search\SortDirection;

/**
 * Immutable receipt of the resolved primary sort — the PUBLIC field name and the
 * direction that ordering was actually applied with.
 *
 * This is the *semantic* sort that feeds the canonical chain
 * (`…|sort|direction|…`), distinct from {@see OrderByColumns}, which is the
 * *physical* column list (including the `id` tie-break) handed to the predicate
 * builder. When a request carries no explicit sort, the resolver defaults this
 * to the `id` tie-break column so the receipt is always concrete.
 */
final readonly class AppliedSort
{
    public function __construct(
        public string $field,
        public SortDirection $direction,
    ) {
    }
}
