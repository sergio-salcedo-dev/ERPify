<?php

declare(strict_types=1);

namespace Erpify\Shared\Search\Infrastructure\Persistence\Doctrine;

/**
 * Normalizes a search value before it is bound to the query, so the comparison matches the
 * canonical form stored in the mapped column. A field's normalizer applies to EVERY operator
 * (eq, in item by item, contains) — searching with the same rule the write side used keeps
 * every operator case- and diacritic-insensitive by construction.
 */
interface FieldNormalizer
{
    public function normalize(string $value): string;
}
