<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Assertion\NodeModifier\Implementation;

use Erpify\Tests\Behat\Assertion\NodeModifier\AbstractNodeModifier;
use Override;

/**
 * Treats the literal string `"null"` (common when values come from Gherkin tables) as an
 * actual `null`, so feature files can assert nullability without type hackery.
 *
 * Example (Gherkin):
 *   And the JSON node "deletedAt" should be equal to "<null>null"
 */
class NullNodeModifier extends AbstractNodeModifier
{
    #[Override]
    public function getModifier(): string
    {
        return 'null';
    }

    #[Override]
    public function getProcessedValue(mixed $value): mixed
    {
        if ('null' === $value || null === $value) {
            return null;
        }

        return $value;
    }
}
