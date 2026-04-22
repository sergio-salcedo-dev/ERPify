<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Assertion\NodeModifier\Implementation;

use Erpify\Tests\Behat\Assertion\NodeModifier\AbstractNodeModifier;
use Override;

/**
 * Coerces the expected value to a real `bool` via `FILTER_VALIDATE_BOOLEAN` so feature
 * files can write `true`/`false`/`1`/`0` as strings and still match the API's boolean
 * response. The literal string `null` (or actual `null`) short-circuits to `null`.
 *
 * Example (Gherkin):
 *   And the JSON node "isActive" should be equal to "<bool>true"
 */
class BooleanNodeModifier extends AbstractNodeModifier
{
    #[Override]
    public function getModifier(): string
    {
        return 'bool';
    }

    #[Override]
    public function getProcessedValue(mixed $value): ?bool
    {
        if ('null' === $value || null === $value) {
            return null;
        }

        return \filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
