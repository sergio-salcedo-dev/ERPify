<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Assertion\NodeModifier\Implementation;

use Erpify\Tests\Behat\Assertion\NodeModifier\AbstractNodeModifier;
use Override;

/**
 * Coerces the expected scalar into an `int` before comparison so numeric values encoded
 * as strings in feature files still match integers returned by the API.
 *
 * Example (Gherkin):
 *   And the JSON node "bank.employees" should be equal to "<int>42"
 */
class IntegerNodeModifier extends AbstractNodeModifier
{
    #[Override]
    public function getModifier(): string
    {
        return 'int';
    }

    #[Override]
    public function getProcessedValue(mixed $value): ?int
    {
        if (null === $value) {
            return null;
        }

        \assert(\is_scalar($value));

        return (int) $value;
    }
}
