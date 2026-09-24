<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional;

/**
 * The property a keyset-paged identifier read owes across its page boundaries: every seeded id is listed, and
 * the whole list is strictly ascending — which also makes it distinct, since a value repeated on either side
 * of a boundary could not be strictly greater than itself.
 *
 * Asserted by containment rather than equality because the test database is shared, so rows other tests left
 * behind may sit between the seeded ones. Postgres writes a `uuid` in lower-case canonical hex, whose byte
 * order is the `uuid` order, so `strcmp` states the same ordering the `ORDER BY` used.
 */
trait AssertsKeysetPagedIds
{
    /**
     * @param list<string> $seeded
     * @param list<string> $ids
     */
    private function assertKeysetPagedIds(array $seeded, array $ids): void
    {
        $this->assertGreaterThanOrEqual(2, \count($seeded), 'at least two ids are needed to cross a page');

        foreach ($seeded as $id) {
            $this->assertContains($id, $ids);
        }

        $previous = null;

        foreach ($ids as $id) {
            if (null !== $previous) {
                $this->assertLessThan(
                    0,
                    \strcmp($previous, $id),
                    \sprintf('ids must be strictly ascending, but "%s" follows "%s"', $id, $previous),
                );
            }

            $previous = $id;
        }
    }
}
