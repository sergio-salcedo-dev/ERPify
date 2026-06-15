<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\NodeModifier;

use Override;

abstract class AbstractNodeModifier implements NodeModifierInterface
{
    #[Override]
    public function getPathCleaned(string $path): string
    {
        return \str_ireplace(\sprintf('::%s', $this->getModifier()), '', $path);
    }

    /**
     * Default: a modifier is reached only through its explicit `field::<modifier>` suffix.
     * Value-aware modifiers (dates, backed enums) override this to opt into auto-detection.
     */
    #[Override]
    public function supportsValue(mixed $value): bool
    {
        return false;
    }

    #[Override]
    public function compare(mixed $expected, mixed $value): bool
    {
        return $this->getProcessedValue($expected) === $this->getProcessedValue($value);
    }
}
