<?php

declare(strict_types=1);

namespace Erpify\Shared\Search\Infrastructure\Persistence\Keyset;

use Erpify\Shared\Search\Domain\Exception\InvalidCursor;
use Erpify\Shared\Search\Domain\NavigationDirection;
use Erpify\Shared\Search\Domain\Page;

/**
 * The keyset (cursor) walk, written once for every store. A {@see KeysetSource} answers how rows are read; this
 * class decides which rows a page holds and which cursors it emits:
 *
 *   1. decode the inbound cursor under the source's fingerprint and the routing direction, then refuse one that
 *      does not cover every ordering key (the codec validates integrity, not shape);
 *   2. fetch `limit + 1` rows past the boundary — a `before` walk reads in the inverted order — and let the
 *      extra row answer "is there more in the direction of travel";
 *   3. trim, restore the caller-facing order, and build the immutable {@see Page} with both cursors.
 *
 * The routing direction is the request's, never the cursor's own `dir`, which is an integrity binding the codec
 * compares and nothing reads to navigate (AR21). Both cursors are read off the boundary ROWS at the source's
 * column precision and keyed by the original ordering keys, so a page fetched `before` round-trips identically
 * to one fetched `after`.
 *
 * Persistence-agnostic by construction: it knows a source, the codec and the domain page, and no query
 * builder of any kind.
 */
final readonly class KeysetPaginator
{
    public function __construct(private CursorCodec $cursorCodec)
    {
    }

    /**
     * @template TRow
     * @template TItem
     *
     * @param KeysetSource<TRow, TItem> $source
     * @param string|null               $encodedCursor the opaque wire token; `null` or `''` on a first page
     *
     * @return Page<TItem>
     */
    public function paginate(KeysetSource $source, ?string $encodedCursor, NavigationDirection $direction): Page
    {
        $fingerprint = $source->fingerprint();
        $cursor = $this->decodeCursor($encodedCursor, $fingerprint, $direction, $source->boundaryColumns());
        $limit = $source->limit();

        $rows = $source->fetch($cursor?->values, $direction, $limit + 1);
        $hasExtra = \count($rows) > $limit;
        $rows = \array_slice($rows, 0, $limit);

        // `before` was fetched in inverted physical order — restore the caller-facing order.
        $isBefore = NavigationDirection::Before === $direction;
        $rows = $isBefore ? \array_reverse($rows) : $rows;

        return $this->buildPage($source, $rows, $hasExtra, $cursor, $isBefore, $fingerprint);
    }

    /**
     * @param non-empty-list<string> $boundaryColumns
     */
    private function decodeCursor(
        ?string $encoded,
        string $fingerprint,
        NavigationDirection $direction,
        array $boundaryColumns,
    ): ?Cursor {
        if (null === $encoded || '' === $encoded) {
            return null;
        }

        $cursor = $this->cursorCodec->decode($encoded, $fingerprint, $this->directionToken($direction));

        // A cursor missing an ordering key would bind a null (matching nothing) or fail inside the adapter's
        // predicate (a 500); either way it is a malformed cursor, so it is refused as one (422).
        foreach ($boundaryColumns as $boundaryColumn) {
            if (!\array_key_exists($boundaryColumn, $cursor->values)) {
                throw InvalidCursor::payload();
            }
        }

        return $cursor;
    }

    /**
     * @template TRow
     * @template TItem
     *
     * @param KeysetSource<TRow, TItem> $source
     * @param list<TRow>                $rows   in caller-facing order
     *
     * @return Page<TItem>
     *
     * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
     */
    private function buildPage(
        KeysetSource $source,
        array $rows,
        bool $hasExtra,
        ?Cursor $cursor,
        bool $isBefore,
        string $fingerprint,
    ): Page {
        $items = \array_map($source->item(...), $rows);
        $count = $source->count();
        $hadCursor = $cursor instanceof Cursor;

        if ([] === $rows) {
            if (!$hadCursor) {
                // First-page empty: a genuinely empty result, no affordance in either direction.
                return new Page([], false, false, $count);
            }

            // Reached via a cursor walking into a gap or an edge. The affordance follows the same directional
            // formula as a populated page with no extra row: an empty `before` keeps hasNext, an empty `after`
            // keeps hasPrev. A true flag must carry a usable cursor, so the recovery cursor re-signs the
            // INBOUND boundary under the OPPOSITE direction — following it walks back toward where you came,
            // landing just past that boundary (the boundary is exclusive).
            return new Page(
                [],
                $isBefore,
                !$isBefore,
                $count,
                $isBefore ? $this->reencodeCursor($cursor, Cursor::DIRECTION_AFTER, $fingerprint) : null,
                $isBefore ? null : $this->reencodeCursor($cursor, Cursor::DIRECTION_BEFORE, $fingerprint),
            );
        }

        $nextCursor = $this->encode($source->position(\array_last($rows)), Cursor::DIRECTION_AFTER, $fingerprint);
        $prevCursor = $this->encode($source->position($rows[0]), Cursor::DIRECTION_BEFORE, $fingerprint);

        // `before` discovers "more rows exist before" via its extra row; `after` discovers "more after".
        return new Page(
            $items,
            $isBefore ? $hadCursor : $hasExtra,
            $isBefore ? $hasExtra : $hadCursor,
            $count,
            $nextCursor,
            $prevCursor,
        );
    }

    private function reencodeCursor(Cursor $cursor, string $direction, string $fingerprint): string
    {
        return $this->encode($cursor->values, $direction, $fingerprint);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function encode(array $values, string $direction, string $fingerprint): string
    {
        return $this->cursorCodec->encode(new Cursor(CursorCodec::CURRENT_VERSION, $direction, $values, $fingerprint));
    }

    private function directionToken(NavigationDirection $direction): string
    {
        return NavigationDirection::Before === $direction ? Cursor::DIRECTION_BEFORE : Cursor::DIRECTION_AFTER;
    }
}
