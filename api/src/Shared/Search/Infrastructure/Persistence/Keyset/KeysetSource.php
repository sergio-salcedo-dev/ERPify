<?php

declare(strict_types=1);

namespace Erpify\Shared\Search\Infrastructure\Persistence\Keyset;

use Erpify\Shared\Search\Domain\NavigationDirection;

/**
 * One search request's query as the {@see KeysetPaginator} sees it: everything that depends on HOW rows are
 * stored and read, and nothing of the walk itself. An adapter settles its ordering, filters, page size and
 * fingerprint before it is handed over (each is part of the cursor's integrity surface), then answers the
 * paginator's questions about that one query.
 *
 * The paginator owns the algorithm — cursor decoding and its column-count guard, the `before` scan
 * inversion, the +1 row, the page flags, both outbound cursors and the empty-page recovery cursor — so an
 * adapter never re-implements any of it, and a fix to the walk lands once for every store.
 *
 * Rows and items are separate on purpose: a cursor boundary is read off the row the store returned, at the
 * store's own column precision, while the page carries whatever the caller reads (the same object for an
 * ORM entity; a hydrated read model for a raw DBAL row).
 *
 * @template TRow
 * @template TItem
 */
interface KeysetSource
{
    /**
     * The ordering keys a cursor of this query must carry a boundary value for — the total order, tie-break
     * last. A decoded cursor missing any of them is refused before {@see fetch()} runs.
     *
     * @return non-empty-list<string>
     */
    public function boundaryColumns(): array;

    /** The binding every cursor of this query is signed under and verified against. */
    public function fingerprint(): string;

    /** The settled page size, at least 1 — the policy-clamped value the fingerprint was derived from. */
    public function limit(): int;

    /**
     * Fetches at most `$maxResults` rows past `$boundary` in the direction of travel. A `before` walk
     * is answered in the INVERTED physical order — the rows nearest the boundary first — so the paginator can
     * trim the extra row from the far end and restore the caller-facing order itself.
     *
     * @param array<string, mixed>|null $boundary the decoded cursor's values keyed by {@see boundaryColumns()};
     *                                            `null` on a first page
     *
     * @return list<TRow>
     */
    public function fetch(?array $boundary, NavigationDirection $direction, int $maxResults): array;

    /**
     * The boundary values of one fetched row, keyed by {@see boundaryColumns()} at the precision the next
     * {@see fetch()} binds them back with.
     *
     * @param TRow $row
     *
     * @return array<string, mixed>
     */
    public function position(mixed $row): array;

    /**
     * @param TRow $row
     *
     * @return TItem
     */
    public function item(mixed $row): mixed;

    /** The total matching the filtered query, or `null` when this request does not count. */
    public function count(): ?int;
}
