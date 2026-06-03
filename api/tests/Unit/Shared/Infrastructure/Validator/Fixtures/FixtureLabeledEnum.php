<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Infrastructure\Validator\Fixtures;

use Erpify\Shared\Domain\Enum\Abstraction\HumanReadableIntEnumInterface;
use Erpify\Shared\Domain\Enum\Abstraction\HumanReadableIntEnumTrait;
use Erpify\Shared\Domain\Enum\Attribute\HumanReadableIntEnumValue;

/**
 * Int-backed enum exposing human-readable labels. Drives the getLabels()
 * branch of EnumTypeValidator::formatChoices().
 */
enum FixtureLabeledEnum: int implements HumanReadableIntEnumInterface
{
    use HumanReadableIntEnumTrait;

    #[HumanReadableIntEnumValue(label: 'one')]
    case ONE = 1;

    #[HumanReadableIntEnumValue(label: 'two')]
    case TWO = 2;

    #[HumanReadableIntEnumValue(label: 'three')]
    case THREE = 3;

    // Intentionally label-less: exercises the null-label fallbacks
    // (dropped in whole-enum listings, replaced by the backing value in subsets).
    case FOUR = 4;
}
