<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Assertion\NodeModifier;

use Override;

abstract class AbstractNodeModifier implements NodeModifierInterface
{
    #[Override]
    public function support(string $path, mixed $value): bool
    {
        return \str_ends_with($path, \sprintf('::%s', $this->getModifier()));
    }

    #[Override]
    public function getPathCleaned(string $path): string
    {
        return \str_ireplace(\sprintf('::%s', $this->getModifier()), '', $path);
    }

    #[Override]
    public function compare(mixed $expected, mixed $value): bool
    {
        return $this->getProcessedValue($expected) === $this->getProcessedValue($value);
    }
}
