<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Behat\Support\Tool\TypeHint\Fixtures;

use RuntimeException;

/**
 * Value object whose constructor always throws — drives the silent-fallback branch
 * of {@see \Erpify\Tests\Behat\Support\Tool\TypeHint\ValueObjectResolver}.
 */
final readonly class ThrowingValueObject
{
    /** Reached only via the resolver's dynamic `new $type()`. */
    public function __construct(mixed $value)
    {
        throw new RuntimeException('rejects every value: ' . \var_export($value, true));
    }
}
