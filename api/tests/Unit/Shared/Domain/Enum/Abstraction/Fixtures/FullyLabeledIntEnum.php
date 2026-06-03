<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Domain\Enum\Abstraction\Fixtures;

use Erpify\Shared\Domain\Enum\Abstraction\HumanReadableIntEnumInterface;
use Erpify\Shared\Domain\Enum\Abstraction\HumanReadableIntEnumTrait;
use Erpify\Shared\Domain\Enum\Attribute\HumanReadableIntEnumValue;

/**
 * Every case carries a label — the only shape a human-readable enum is allowed
 * to have. Drives the happy-path label methods of {@see HumanReadableIntEnumTrait}.
 */
enum FullyLabeledIntEnum: int implements HumanReadableIntEnumInterface
{
    use HumanReadableIntEnumTrait;

    #[HumanReadableIntEnumValue(label: 'first')]
    case FIRST = 1;

    #[HumanReadableIntEnumValue(label: 'second')]
    case SECOND = 2;
}
