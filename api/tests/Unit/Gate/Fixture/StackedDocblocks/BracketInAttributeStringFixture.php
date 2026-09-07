<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate\Fixture\StackedDocblocks;

use PHPUnit\Framework\Attributes\TestWith;

final class BracketInAttributeStringFixture
{
    /**
     * Inert. The `]` inside the attribute's string argument is what ends the group early for any scan that
     * reads brackets as text rather than as tokens — and an early end puts the group's tail back between
     * the two blocks, which stops them pairing.
     */
    #[TestWith(['][', 'a[b]c'])]
    /**
     * The block the declaration actually carries.
     */
    public function value(string $first, string $second): int
    {
        return \strlen($first . $second);
    }
}
