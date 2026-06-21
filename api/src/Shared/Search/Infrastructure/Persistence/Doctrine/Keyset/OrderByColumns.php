<?php

declare(strict_types=1);

namespace Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\Keyset;

use Erpify\Shared\Search\Domain\SortDirection;

/**
 * The physical, ordered list of ORDER BY columns for a keyset query, with the
 * `id` tie-break guaranteed present as the LAST key.
 *
 * Keyset pagination is only deterministic when the ordering is total: the
 * tie-break makes equal primary-sort values resolve by a unique key, so the
 * boundary predicate never straddles a tie. This VO enforces that invariant at
 * construction — every factory ends in {@see OrderByColumns::TIE_BREAK_COLUMN}.
 *
 * Distinct from {@see AppliedSort}: that is the *semantic* sort (the public
 * field) feeding the canonical chain; this is the *physical* column list handed
 * to {@see KeysetPredicateBuilder}.
 */
final readonly class OrderByColumns
{
    public const string TIE_BREAK_COLUMN = 'id';

    /**
     * @param non-empty-list<array{column: string, direction: SortDirection}> $columns
     */
    private function __construct(private array $columns)
    {
    }

    public static function fromPrimarySort(string $column, SortDirection $direction): self
    {
        return self::fromSorts([['column' => $column, 'direction' => $direction]]);
    }

    /**
     * Builds the column list from N primary sort keys, appending the `id` tie-break unless the last
     * key already IS the tie-break column (sorting by `id` directly is already a total order).
     *
     * Any `id` key that is NOT in the final position is dropped first: the tie-break is appended
     * exactly once as the last column, so a mid-list `id` would duplicate it in both the ORDER BY and
     * the keyset predicate (a duplicated boundary key over-constrains the walk). Resolved as the
     * deferred review item from story 1.1, where PR2 first wires real multi-key sorts.
     *
     * @param non-empty-list<array{column: string, direction: SortDirection}> $sorts
     */
    public static function fromSorts(array $sorts): self
    {
        $lastIndex = \array_key_last($sorts);
        $last = $sorts[$lastIndex];

        $preceding = [];

        foreach ($sorts as $index => $sort) {
            if ($index === $lastIndex) {
                continue;
            }

            if (self::TIE_BREAK_COLUMN === $sort['column']) {
                continue;
            }

            $preceding[] = $sort;
        }

        // The last key is always kept, so the list is non-empty; any earlier `id` was dropped above.
        $columns = [...$preceding, $last];

        if (self::TIE_BREAK_COLUMN === $last['column']) {
            return new self($columns);
        }

        $columns[] = ['column' => self::TIE_BREAK_COLUMN, 'direction' => SortDirection::ASC];

        return new self($columns);
    }

    public static function tieBreakOnly(): self
    {
        return new self([['column' => self::TIE_BREAK_COLUMN, 'direction' => SortDirection::ASC]]);
    }

    /**
     * @return non-empty-list<array{column: string, direction: SortDirection}>
     */
    public function all(): array
    {
        return $this->columns;
    }

    /**
     * @return non-empty-list<string>
     */
    public function columnNames(): array
    {
        return \array_map(static fn (array $column): string => $column['column'], $this->columns);
    }

    public function tieBreakColumn(): string
    {
        return \array_reverse($this->columns)[0]['column'];
    }
}
