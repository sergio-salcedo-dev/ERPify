<?php

declare(strict_types=1);

namespace Erpify\Shared\Infrastructure\Persistence\Doctrine\Search;

/**
 * Normalizes a search value before it is bound to the query, so the comparison matches the
 * canonical form stored in the mapped column. A field's normalizer applies to EVERY operator
 * (eq, in item by item, contains) — that is what guarantees the legacy `names[]` ≡
 * `filters[name][in]` equivalence.
 */
interface FieldNormalizer
{
    public function normalize(string $value): string;
}
