<?php

declare(strict_types=1);

namespace Erpify\Shared\Domain\Enum\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS_CONSTANT)]
readonly class HumanReadableIntEnumValue
{
    public function __construct(
        public ?string $label = null,
    ) {
    }
}
