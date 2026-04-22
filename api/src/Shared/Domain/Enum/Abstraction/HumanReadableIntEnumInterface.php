<?php

declare(strict_types=1);

namespace Erpify\Shared\Domain\Enum\Abstraction;

use BackedEnum;

interface HumanReadableIntEnumInterface extends BackedEnum
{
    public function getLabel(): ?string;

    public function getLabelOrFail(): string;

    /** @return array<int, string|null> */
    public static function getLabels(): array;

    public static function fromLabel(string $label): ?self;

    public static function fromLabelOrFail(string $label): static;
}
