<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate\Fixture\StackedDocblocks;

final class TripleStackedFixture
{
    /**
     * First of three: inert.
     */
    /**
     * Second of three: also inert, and a gate reporting only one of them would leave this one behind.
     */
    /**
     * The block the declaration actually carries.
     */
    public function value(): int
    {
        return 1;
    }
}
