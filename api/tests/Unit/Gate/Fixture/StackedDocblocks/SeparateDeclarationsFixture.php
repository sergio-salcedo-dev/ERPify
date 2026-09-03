<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate\Fixture\StackedDocblocks;

/**
 * Two doc comments in one file, each binding its own declaration. The ordinary shape, and the one a gate
 * that merely counted doc comments per file would report.
 */
final class SeparateDeclarationsFixture
{
    /**
     * Binds this method.
     */
    public function first(): int
    {
        return 1;
    }

    /**
     * Binds this one.
     */
    public function second(): int
    {
        return 2;
    }
}
