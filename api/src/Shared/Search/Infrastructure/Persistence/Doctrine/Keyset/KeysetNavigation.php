<?php

declare(strict_types=1);

namespace Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\Keyset;

/**
 * The resolved state of one navigation step, settled before any row is fetched: which columns order the walk,
 * the boundary it starts from (none on a first page), the direction it walks in and the fingerprint every
 * cursor it emits is signed under. The fetch, the page flags and every outbound cursor read all four, and read
 * them together, so they travel as one value rather than as four arguments that could be passed out of step.
 *
 * The direction is held once, as the wire string, and {@see isBefore()} derives from it: carrying the same fact
 * a second time as a separate boolean is how two readers of one step come to disagree about which way it went.
 */
final readonly class KeysetNavigation
{
    public function __construct(
        public OrderByColumns $orderByColumns,
        public ?Cursor $cursor,
        public string $direction,
        public string $fingerprint,
    ) {
    }

    /**
     * `before` is contained inside the engine: the ORDER BY is inverted in SQL and the page re-reversed in
     * memory, so no caller of the engine ever sees it.
     */
    public function isBefore(): bool
    {
        return Cursor::DIRECTION_BEFORE === $this->direction;
    }

    public function hasCursor(): bool
    {
        return $this->cursor instanceof Cursor;
    }
}
