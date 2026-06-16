<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Support;

use OutOfRangeException;
use RuntimeException;

/**
 * Picks one recorded item (a Mercure update, a notification email, …) from a captured list, given an
 * optional explicit 1-based-derived selection index. Plain support class so it can be unit-tested
 * outside the Behat runtime (the contexts that use it implement Behat's `Context` interface, which is
 * only autoloadable from the isolated Behat tool tree).
 *
 * It fails loudly rather than guessing: an empty list, an out-of-range index, or — the trap it exists
 * to close — an unselected pick when several items were recorded all throw, so a scenario can never
 * silently assert against the wrong (last) item once a handler starts producing more than one.
 */
final class RecordedItemSelector
{
    /**
     * @template T
     *
     * @param list<T>  $items
     * @param int|null $selectedIndex zero-based selection, or null to default to the only item
     *
     * @return T
     */
    public function select(array $items, ?int $selectedIndex, string $label): mixed
    {
        if ([] === $items) {
            throw new RuntimeException(\sprintf('No %s has been recorded', $label));
        }

        if (null === $selectedIndex) {
            if (\count($items) > 1) {
                throw new RuntimeException(\sprintf(
                    'Ambiguous selection: %d %ss were recorded; select one explicitly before asserting',
                    \count($items),
                    $label,
                ));
            }

            return $items[0];
        }

        if (!\array_key_exists($selectedIndex, $items)) {
            throw new OutOfRangeException(\sprintf('No %s number %d', $label, $selectedIndex + 1));
        }

        return $items[$selectedIndex];
    }
}
