<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\NodeModifier\Matching;

use Erpify\Tests\Behat\NodeModifier\AbstractNodeModifier;
use Override;

/**
 * Asserts the actual value is one of a comma-separated allow-list of expected candidates.
 * Useful for fields whose valid values are non-deterministic (e.g. randomised ordering).
 *
 * Example (Gherkin):
 *   And the JSON node "status" should be equal to "<oneOf>active,pending,archived"
 */
class OneOfNodeModifier extends AbstractNodeModifier
{
    #[Override]
    public function getModifier(): string
    {
        return 'oneOf';
    }

    #[Override]
    public function getProcessedValue(mixed $value): mixed
    {
        return $value;
    }

    #[Override]
    public function compare(mixed $expected, mixed $value): bool
    {
        \assert(\is_scalar($expected));

        return \in_array($value, \explode(',', (string) $expected), true);
    }
}
