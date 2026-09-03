<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate\Fixture\StackedDocblocks;

/**
 * The plain shape: two doc comments, nothing between them. PHP binds the second, so this one is inert.
 */
/**
 * The block the declaration actually carries.
 */
final class StackedFixture
{
    public function value(): int
    {
        return 1;
    }
}
