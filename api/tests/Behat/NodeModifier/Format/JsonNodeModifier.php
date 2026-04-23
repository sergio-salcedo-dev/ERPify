<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\NodeModifier\Format;

use Erpify\Tests\Behat\NodeModifier\AbstractNodeModifier;
use Erpify\Tests\Behat\Support\Tool\ArrayTools;
use JsonException;
use Override;

/**
 * Decodes the expected JSON string and deep-sorts both sides before comparison so nested
 * structures can be asserted without caring about array key/element ordering.
 *
 * Example (Gherkin):
 *   And the JSON node "metadata" should be equal to '<json>{"tags":["b","a"],"count":2}'
 *   // matches a response where "tags" arrives as ["a","b"] in any order.
 */
class JsonNodeModifier extends AbstractNodeModifier
{
    #[Override]
    public function getModifier(): string
    {
        return 'json';
    }

    /**
     * @throws JsonException
     */
    #[Override]
    public function getProcessedValue(mixed $value): mixed
    {
        if (\is_array($value)) {
            return $value;
        }

        if (!\is_string($value)) {
            return $value;
        }

        return \json_decode($value, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @throws JsonException
     */
    #[Override]
    public function compare(mixed $expected, mixed $value): bool
    {
        $expected = $this->getProcessedValue($expected);
        ArrayTools::fullSort($expected);

        $value = $this->getProcessedValue($value);
        ArrayTools::fullSort($value);

        return $expected === $value;
    }
}
