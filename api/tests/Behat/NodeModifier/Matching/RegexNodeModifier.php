<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\NodeModifier\Matching;

use Erpify\Tests\Behat\NodeModifier\AbstractNodeModifier;
use Override;

/**
 * Matches the actual value against a PCRE pattern supplied as the expected side — handy
 * for fields with unpredictable shape such as UUIDs, slugs, or generated identifiers.
 *
 * Example (Gherkin):
 *   And the JSON node "id" should be equal to "<regex>/^[0-9a-f-]{36}$/"
 */
class RegexNodeModifier extends AbstractNodeModifier
{
    #[Override]
    public function getModifier(): string
    {
        return 'regex';
    }

    #[Override]
    public function getProcessedValue(mixed $value): mixed
    {
        return $value;
    }

    #[Override]
    public function compare(mixed $expected, mixed $value): bool
    {
        \assert(\is_string($expected));
        \assert(\is_scalar($value));
        $result = \preg_match($expected, (string) $value);

        return false !== $result && $result > 0;
    }
}
