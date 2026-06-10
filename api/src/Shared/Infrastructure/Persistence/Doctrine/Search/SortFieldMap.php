<?php

declare(strict_types=1);

namespace Erpify\Shared\Infrastructure\Persistence\Doctrine\Search;

/**
 * Mandatory allow-list of publicly sortable fields, keyed by the PUBLIC field name clients send
 * in `sort` (never DQL paths). Sibling to {@see SearchFieldMap} but intentionally separate:
 * sorting and filtering are independent allow-lists (a field may be sortable and not filterable,
 * or vice versa), and ordering only needs name → DQL path — no operators, normalizers or flags.
 *
 * Built exclusively inside each concrete repository, where the schema knowledge lives. A field
 * without an entry is not sortable: {@see AbstractDoctrineSearchRepository} rejects it with an
 * `UnknownSortField` (400) rather than ever interpolating the client value into DQL.
 */
final readonly class SortFieldMap
{
    /**
     * @param array<string, string> $paths public field name → DQL order-by path (e.g. `b.createdAt`)
     */
    public function __construct(
        private array $paths,
    ) {
    }

    public function pathFor(string $field): ?string
    {
        return $this->paths[$field] ?? null;
    }
}
