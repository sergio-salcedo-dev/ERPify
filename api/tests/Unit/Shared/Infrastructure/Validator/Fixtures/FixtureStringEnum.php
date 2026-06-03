<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Infrastructure\Validator\Fixtures;

/**
 * Plain string-backed enum with no human-readable labels. Drives the
 * value-fallback branch of EnumTypeValidator::formatChoices().
 */
enum FixtureStringEnum: string
{
    case A = 'a';
    case B = 'b';
    case C = 'c';
}
