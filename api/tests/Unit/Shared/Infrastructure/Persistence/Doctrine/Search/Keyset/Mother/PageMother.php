<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Infrastructure\Persistence\Doctrine\Search\Keyset\Mother;

use Erpify\Shared\Domain\Search\Page;

/**
 * Builds {@see Page} instances for the domain port tests.
 */
final class PageMother
{
    /**
     * @param list<string> $items
     *
     * @return Page<string>
     *
     * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
     */
    public static function create(
        array $items = ['BBVA', 'Caixa'],
        bool $hasNext = true,
        bool $hasPrev = false,
        ?int $count = null,
        ?string $nextCursor = 'next-token',
        ?string $prevCursor = null,
    ): Page {
        return new Page($items, $hasNext, $hasPrev, $count, $nextCursor, $prevCursor);
    }
}
