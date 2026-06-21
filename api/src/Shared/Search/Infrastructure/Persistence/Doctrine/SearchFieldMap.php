<?php

declare(strict_types=1);

namespace Erpify\Shared\Search\Infrastructure\Persistence\Doctrine;

/**
 * Mandatory allow-list of searchable fields, keyed by the PUBLIC field name clients send in
 * `filters[N][field]` (never DQL paths). Built exclusively inside each concrete repository —
 * the allow-list lives with the schema knowledge. A field without an entry is not filterable:
 * the applier rejects it with `UnknownSearchField`.
 */
final readonly class SearchFieldMap
{
    /**
     * @param array<string, FieldMapping> $mappings keyed by public field name
     */
    public function __construct(
        private array $mappings,
    ) {
    }

    public function mappingFor(string $field): ?FieldMapping
    {
        return $this->mappings[$field] ?? null;
    }
}
