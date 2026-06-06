<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Behat\Support\Tool\TypeHint\Fixtures;

/**
 * Single-string-argument value object — drives the happy-path construction branch
 * of {@see \Erpify\Tests\Behat\Support\Tool\TypeHint\ValueObjectResolver}.
 */
final class StringValueObject
{
    /** @psalm-suppress PossiblyUnusedMethod reached only via the resolver's dynamic `new $type()` */
    public function __construct(public string $value)
    {
    }
}
