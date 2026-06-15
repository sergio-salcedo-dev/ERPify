<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\NodeModifier;

use Override;

abstract class AbstractNodeModifier implements NodeModifierInterface
{
    #[Override]
    public function getPathCleaned(string $path): string
    {
        $suffix = \sprintf('::%s', $this->getModifier());

        // Strip only the trailing `::<modifier>` suffix, matching how NodeModifierLocator
        // extracts the modifier name (last `::` segment, case-sensitive). A global/case-
        // insensitive replace would corrupt a path that carries the token earlier or in
        // another case (e.g. `a::stringfield::string`).
        if (\str_ends_with($path, $suffix)) {
            return \substr($path, 0, -\strlen($suffix));
        }

        return $path;
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
