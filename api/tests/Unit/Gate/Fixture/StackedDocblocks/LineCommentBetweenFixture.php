<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate\Fixture\StackedDocblocks;

final class LineCommentBetweenFixture
{
    /**
     * Measured: a `//` comment does not separate them either.
     */
    // An ordinary comment, which binds nothing and hides nothing.
    /**
     * The block the declaration actually carries.
     */
    public function value(): int
    {
        return 1;
    }
}
