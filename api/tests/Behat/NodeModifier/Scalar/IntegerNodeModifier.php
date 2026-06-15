<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\NodeModifier\Scalar;

use Erpify\Tests\Behat\NodeModifier\AbstractNodeModifier;
use Override;

/**
 * Coerces the expected scalar into an `int` before comparison so numeric values encoded
 * as strings in feature files still match integers returned by the API.
 *
 * Example (Gherkin):
 *   And the JSON node "bank.employees::int" should be equal to "42"
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
        // A non-scalar actual (array/object) cannot be coerced to an int: return null so the
        // comparison fails cleanly instead of aborting on the cast.
        if (null === $value || !\is_scalar($value)) {
            return null;
        }

        return (int) $value;
    }
}
