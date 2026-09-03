<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate\Fixture\StackedDocblocks;

use Deprecated;

final class AttributeBetweenFixture
{
    /**
     * Measured: PHP binds past an attribute, so this block is inert exactly as in the plain shape.
     */
    #[Deprecated]
    /**
     * The block the declaration actually carries.
     */
    public function value(): int
    {
        return 1;
    }
}
