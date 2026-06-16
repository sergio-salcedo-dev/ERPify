<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Infrastructure\Validator\Fixtures;

/**
 * A second, distinct string-backed enum. Drives the "instance of another enum" rejection branch of
 * EnumTypeValidator (a valid case of the wrong enum class must still be rejected).
 */
enum FixtureOtherStringEnum: string
{
    case ONE = 'one';
    case TWO = 'two';
    case THREE = 'three';
}
