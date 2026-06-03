<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Domain\Enum\Abstraction\Fixtures;

use Erpify\Shared\Domain\Enum\Abstraction\HumanReadableIntEnumInterface;
use Erpify\Shared\Domain\Enum\Abstraction\HumanReadableIntEnumTrait;
use Erpify\Shared\Domain\Enum\Attribute\HumanReadableIntEnumValue;

/**
 * One case deliberately omits the attribute — a forbidden shape. Drives the
 * fail-fast guard in {@see HumanReadableIntEnumTrait}: accessing any label
 * method must throw rather than silently skipping the case.
 */
enum MissingLabelIntEnum: int implements HumanReadableIntEnumInterface
{
    use HumanReadableIntEnumTrait;

    #[HumanReadableIntEnumValue(label: 'present')]
    case PRESENT = 1;

    case ABSENT = 2;
}
