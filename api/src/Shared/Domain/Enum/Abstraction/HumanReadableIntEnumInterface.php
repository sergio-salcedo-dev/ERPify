<?php

declare(strict_types=1);

namespace Erpify\Shared\Domain\Enum\Abstraction;

use BackedEnum;

interface HumanReadableIntEnumInterface extends BackedEnum
{
    public function getLabel(): string;

    /** @return list<string> */
    public static function getLabels(): array;

    public static function fromLabel(string $label): ?self;

    public static function fromLabelOrFail(string $label): static;
}
