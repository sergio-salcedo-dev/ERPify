<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\NodeModifier\Scalar;

use Erpify\Tests\Behat\NodeModifier\AbstractNodeModifier;
use Override;

/**
 * Coerces the expected value to a string before comparison — use when the API returns a
 * value encoded as a string (e.g. numeric identifiers serialised as JSON strings) but the
 * feature file writes it as a raw scalar.
 *
 * Example (Gherkin):
 *   And the JSON node "accountNumber" should be equal to "<string>1234567890"
 */
class StringNodeModifier extends AbstractNodeModifier
{
    #[Override]
    public function getModifier(): string
    {
        return 'string';
    }

    #[Override]
    public function getProcessedValue(mixed $value): ?string
    {
        // A non-scalar actual (array/object) is not a string: return null so the comparison
        // fails cleanly instead of aborting on the cast.
        if (null !== $value && !\is_scalar($value)) {
            return null;
        }

        return (string) $value;
    }
}
